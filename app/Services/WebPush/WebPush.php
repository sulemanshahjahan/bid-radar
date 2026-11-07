<?php

namespace App\Services\WebPush;

use App\Models\Project;
use App\Models\Setting;
use App\Services\Settings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Browser push notifications (Web Push with VAPID, RFC 8291/8292), no extra packages.
 *
 * The VAPID key pair is created on first use and kept in the settings table (private key
 * encrypted). Subscriptions are kept there too: this is a single-user app with a few devices.
 * They are read straight from the database, not the Settings singleton, so a long-running
 * queue worker sees devices added after it started.
 */
class WebPush
{
    public const KEYS = 'push_vapid';

    public const SUBSCRIPTIONS = 'push_subscriptions';

    /** P-256 SubjectPublicKeyInfo prefix; append the 65-byte uncompressed point to get DER. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(private readonly Settings $settings) {}

    /** The public VAPID key (base64url) the browser subscribes with. Creates the key pair on first use. */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    /** @return list<array{endpoint: string, p256dh: string, auth: string}> */
    public function subscriptions(): array
    {
        $value = Setting::query()->where('key', self::SUBSCRIPTIONS)->value('value');

        return array_values(array_filter(is_array($value) ? $value : [], fn ($s) => is_array($s)
            && is_string($s['endpoint'] ?? null) && is_string($s['p256dh'] ?? null) && is_string($s['auth'] ?? null)));
    }

    /** @param array{endpoint: string, p256dh: string, auth: string} $subscription */
    public function subscribe(array $subscription): void
    {
        $all = array_values(array_filter($this->subscriptions(), fn ($s) => $s['endpoint'] !== $subscription['endpoint']));
        $all[] = $subscription;
        $this->settings->set([self::SUBSCRIPTIONS => array_slice($all, -10)]);
    }

    public function unsubscribe(string $endpoint): void
    {
        $this->settings->set([self::SUBSCRIPTIONS => array_values(array_filter($this->subscriptions(), fn ($s) => $s['endpoint'] !== $endpoint))]);
    }

    /** Push a scored live project to every subscribed device. Returns how many devices accepted it. */
    public function projectAlert(Project $project): int
    {
        $d = $project->details ?? [];
        $budget = trim(($d['currency'] ?? '').' '.self::money($d['budget_min'] ?? null).'–'.self::money($d['budget_max'] ?? null))
            .(($d['type'] ?? 'fixed') === 'hourly' ? '/hr' : '');

        return $this->send([
            'title' => $project->fit_score.' · '.mb_substr((string) $project->title, 0, 90),
            'body' => $budget.' · '.(int) ($d['bid_count'] ?? 0).' bids',
            'url' => route('projects.show', $project),
            'tag' => 'project-'.$project->id,
        ]);
    }

    /**
     * @param  array<string, string>  $payload  title, body, url, tag
     */
    public function send(array $payload): int
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sent = 0;
        foreach ($this->subscriptions() as $sub) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'TTL' => '600',
                        'Urgency' => 'high',
                        'Content-Encoding' => 'aes128gcm',
                        'Authorization' => $this->vapidHeader($sub['endpoint']),
                    ])
                    ->withBody($this->encrypt($json, $sub['p256dh'], $sub['auth']), 'application/octet-stream')
                    ->post($sub['endpoint']);

                if (in_array($response->status(), [404, 410], true)) {
                    $this->unsubscribe($sub['endpoint']); // the browser dropped this subscription
                } elseif ($response->successful()) {
                    $sent++;
                } else {
                    Log::warning('Web push rejected', ['status' => $response->status(), 'host' => parse_url($sub['endpoint'], PHP_URL_HOST)]);
                }
            } catch (Throwable $e) {
                Log::warning('Web push failed', ['error' => $e->getMessage(), 'host' => parse_url($sub['endpoint'], PHP_URL_HOST)]);
            }
        }

        return $sent;
    }

    /** RFC 8291 aes128gcm body for one subscription. */
    public function encrypt(string $plaintext, string $p256dh, string $auth): string
    {
        $uaPublic = self::b64d($p256dh);
        $authSecret = self::b64d($auth);
        if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) {
            throw new RuntimeException('Invalid push subscription keys.');
        }

        $local = self::newKey();
        $asPublic = self::rawPublic($local);
        $shared = openssl_pkey_derive(openssl_pkey_get_public(self::pemFromRaw($uaPublic)), $local, 32);
        if ($shared === false) {
            throw new RuntimeException('ECDH failed.');
        }

        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Push encryption failed.');
        }

        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    /** "vapid t=<JWT>, k=<public key>" for the push service that owns the endpoint. */
    public function vapidHeader(string $endpoint): string
    {
        $parts = parse_url($endpoint);
        $keys = $this->keys();
        $header = self::b64e(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64e(json_encode([
            'aud' => $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : ''),
            'exp' => time() + 12 * 3600,
            'sub' => str_starts_with((string) config('app.url'), 'https://') ? config('app.url') : 'mailto:owner@bidradar.invalid',
        ], JSON_UNESCAPED_SLASHES));

        $der = '';
        if (! openssl_sign("{$header}.{$claims}", $der, Crypt::decryptString($keys['private']), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('VAPID signing failed.');
        }

        return "vapid t={$header}.{$claims}.".self::b64e(self::derToRaw($der)).", k={$keys['public']}";
    }

    /** @return array{public: string, private: string} private is the encrypted PEM */
    private function keys(): array
    {
        $stored = Setting::query()->where('key', self::KEYS)->value('value');
        if (is_array($stored) && isset($stored['public'], $stored['private'])) {
            return $stored;
        }

        $key = self::newKey();
        openssl_pkey_export($key, $pem, null, self::opensslConfig());
        $keys = ['public' => self::b64e(self::rawPublic($key)), 'private' => Crypt::encryptString($pem)];
        $this->settings->set([self::KEYS => $keys]);

        return $keys;
    }

    private static function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + self::opensslConfig());
        if ($key === false) {
            throw new RuntimeException('Could not create a P-256 key: '.openssl_error_string());
        }

        return $key;
    }

    /** Windows PHP builds often need the path to openssl.cnf to create keys. */
    private static function opensslConfig(): array
    {
        $cnf = getenv('OPENSSL_CONF');
        if (! $cnf && PHP_OS_FAMILY === 'Windows') {
            $guess = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
            $cnf = is_file($guess) ? $guess : null;
        }

        return $cnf ? ['config' => $cnf] : [];
    }

    private static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function pemFromRaw(string $raw): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX).$raw), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    /** ECDSA DER signature (SEQUENCE of two INTEGERs) to the 64-byte r||s form JWS needs. */
    private static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = ltrim(substr($der, $offset + 2, $len), "\0");
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }

        return $out;
    }

    public static function b64e(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64d(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4));
    }

    private static function money(mixed $value): string
    {
        return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.') : '?';
    }
}
