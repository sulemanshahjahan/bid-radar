<?php

namespace Tests\Feature;

use App\Http\Controllers\AlertFeedController;
use App\Jobs\ScoreProject;
use App\Livewire\SettingsPage;
use App\Models\User;
use App\Services\ScoreAdjuster;
use App\Services\Scoring\Scorer;
use App\Services\Settings;
use App\Services\WebPush\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://push.example.test/send/abc123';

    /** A fake browser: its key pair and auth secret, to decrypt what the server sends. */
    private \OpenSSLAsymmetricKey $browserKey;

    private string $browserAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $cnf = PHP_OS_FAMILY === 'Windows' && is_file(dirname(PHP_BINARY).'/extras/ssl/openssl.cnf') ? ['config' => dirname(PHP_BINARY).'/extras/ssl/openssl.cnf'] : [];
        $this->browserKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + $cnf);
        $this->browserAuth = random_bytes(16);
    }

    private function browserPublic(): string
    {
        $ec = openssl_pkey_get_details($this->browserKey)['ec'];

        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private function subscribe(): void
    {
        app(WebPush::class)->subscribe([
            'endpoint' => self::ENDPOINT,
            'p256dh' => WebPush::b64e($this->browserPublic()),
            'auth' => WebPush::b64e($this->browserAuth),
        ]);
    }

    /** RFC 8291 decryption, as the browser does it. */
    private function decrypt(string $body): string
    {
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $cipher = substr($body, 21 + $idLen, -16);
        $tag = substr($body, -16);

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$asPublic), 64, "\n")."-----END PUBLIC KEY-----\n";
        $shared = openssl_pkey_derive(openssl_pkey_get_public($pem), $this->browserKey, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$this->browserPublic().$asPublic, $this->browserAuth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt($cipher, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);

        $this->assertNotFalse($plain, 'payload must decrypt with the browser keys');
        $this->assertSame("\x02", substr($plain, -1), 'last-record padding delimiter');

        return substr($plain, 0, -1);
    }

    public function test_payload_decrypts_with_the_browser_keys_and_the_vapid_jwt_verifies(): void
    {
        $this->subscribe();
        Http::fake([self::ENDPOINT => Http::response('', 201)]);

        $sent = app(WebPush::class)->send(['title' => 'Hello', 'body' => 'World', 'url' => 'https://bids.test/projects/1', 'tag' => 't']);

        $this->assertSame(1, $sent);
        Http::assertSent(function (Request $request) {
            $this->assertSame('aes128gcm', $request->header('Content-Encoding')[0]);
            $this->assertSame('high', $request->header('Urgency')[0]);
            $this->assertSame(['title' => 'Hello', 'body' => 'World', 'url' => 'https://bids.test/projects/1', 'tag' => 't'], json_decode($this->decrypt($request->body()), true));

            preg_match('/^vapid t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $request->header('Authorization')[0], $m);
            $this->assertCount(5, $m, 'vapid header shape');
            $claims = json_decode(WebPush::b64d($m[2]), true);
            $this->assertSame('https://push.example.test', $claims['aud']);
            $this->assertGreaterThan(time(), $claims['exp']);

            // Verify the ES256 signature with the advertised public key (raw r||s back to DER).
            $raw = WebPush::b64d($m[3]);
            $int = fn (string $v) => (ord(ltrim($v, "\0")[0] ?? "\0") & 0x80 ? "\0" : '').ltrim($v, "\0");
            [$r, $s] = [$int(substr($raw, 0, 32)), $int(substr($raw, 32))];
            $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;
            $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').WebPush::b64d($m[4])), 64, "\n")."-----END PUBLIC KEY-----\n";
            $this->assertSame(1, openssl_verify("{$m[1]}.{$m[2]}", $der, $pem, OPENSSL_ALGO_SHA256));

            return true;
        });
    }

    public function test_the_key_pair_is_created_once_and_the_private_key_is_stored_encrypted(): void
    {
        $push = app(WebPush::class);
        $key = $push->publicKey();

        $this->assertSame($key, app(WebPush::class)->publicKey());
        $this->assertSame(65, strlen(WebPush::b64d($key)));
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode(app(Settings::class)->get(WebPush::KEYS)));
    }

    public function test_a_gone_subscription_is_removed(): void
    {
        $this->subscribe();
        Http::fake([self::ENDPOINT => Http::response('', 410)]);

        $this->assertSame(0, app(WebPush::class)->send(['title' => 'x', 'body' => 'y', 'url' => '/', 'tag' => 't']));
        $this->assertSame([], app(WebPush::class)->subscriptions());
    }

    public function test_a_live_project_scored_above_zero_is_pushed_once_even_when_the_draft_gate_holds_it(): void
    {
        $this->subscribe();
        Http::fake([self::ENDPOINT => Http::response('', 201)]);
        $project = $this->makeProject(['source' => 'freelancer', 'url' => 'https://www.freelancer.com/projects/x'], ['bid_count' => 3]);
        $this->claude->reply($this->scoringReply(['fit_score' => 12])); // below "show at", so held

        ScoreProject::dispatchSync($project->id);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_starts_with(json_decode($this->decrypt($r->body()), true)['title'], '12 · WooCommerce checkout'));
        $this->assertSame(1, $project->fresh()->decision['push']['devices']);

        // Scoring again (e.g. a retry) does not push twice.
        $this->claude->reply($this->scoringReply(['fit_score' => 12]));
        ScoreProject::dispatchSync($project->id);
        Http::assertSentCount(1);
    }

    public function test_score_zero_pasted_or_paused_projects_are_not_pushed(): void
    {
        $this->subscribe();
        Http::fake();

        $zero = $this->makeProject(['source' => 'freelancer']);
        $this->claude->reply($this->scoringReply(['fit_score' => 0]));
        ScoreProject::dispatchSync($zero->id);

        $pasted = $this->makeProject();
        $this->claude->reply($this->scoringReply(['fit_score' => 40]));
        ScoreProject::dispatchSync($pasted->id);

        app(Settings::class)->set(['paused' => true]);
        $paused = $this->makeProject(['source' => 'freelancer']);
        $this->claude->reply($this->scoringReply(['fit_score' => 40]));
        ScoreProject::dispatchSync($paused->id);

        Http::assertNothingSent();
    }

    public function test_the_alert_feed_needs_the_token_and_lists_only_pushed_projects(): void
    {
        $this->get('/alerts/feed')->assertStatus(401);

        $this->actingAs(User::factory()->create());
        $component = Livewire::test(SettingsPage::class)->call('createFeedToken');
        $token = $component->get('feedToken');
        $component->assertSee($token);
        $this->assertSame(hash('sha256', $token), app(Settings::class)->get(AlertFeedController::TOKEN_HASH));
        auth()->logout();

        $this->withToken('wrong')->get('/alerts/feed')->assertStatus(401);

        $hit = $this->makeProject(['source' => 'freelancer', 'url' => 'https://www.freelancer.com/projects/hit', 'fit_score' => 5, 'scored_at' => now()->subMinute(), 'ai_state' => 'scored'],
            [], $this->scoringReply(['fit_score' => 5, 'client_test_phrase' => 'BLUE']));
        $hit->forceFill(['fit_score' => 5])->save();
        $this->makeProject(['source' => 'freelancer', 'fit_score' => 0, 'scored_at' => now()->subMinute(), 'ai_state' => 'scored']);
        $this->makeProject(['source' => 'paste', 'fit_score' => 90, 'scored_at' => now()->subMinute(), 'ai_state' => 'scored']);

        $json = $this->withToken($token)->get('/alerts/feed?since='.now()->subHour()->getTimestamp())->assertOk()->json();

        $this->assertSame([$hit->id], array_column($json['projects'], 'id'));
        $this->assertSame('https://www.freelancer.com/projects/hit', $json['projects'][0]['freelancer_url']);
        $this->assertSame('BLUE', $json['projects'][0]['client_test_phrase']);
        $this->assertSame(150, $json['projects'][0]['budget_min']);
    }

    public function test_settings_never_puts_push_keys_or_subscriptions_in_the_form(): void
    {
        $this->subscribe();
        app(WebPush::class)->publicKey();
        $this->actingAs(User::factory()->create());

        $form = Livewire::test(SettingsPage::class)->assertSee('Browser push')->assertSee('Subscribed devices')->get('form');

        $this->assertArrayNotHasKey(WebPush::KEYS, $form);
        $this->assertArrayNotHasKey(WebPush::SUBSCRIPTIONS, $form);
        $this->assertSame(1, $form['push_min_score']);
    }

    public function test_subscribe_endpoint_saves_the_device(): void
    {
        $this->actingAs(User::factory()->create());

        $this->postJson('/push/subscribe', ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => WebPush::b64e($this->browserPublic()), 'auth' => WebPush::b64e($this->browserAuth)]])
            ->assertOk()->assertJson(['devices' => 1]);
        $this->postJson('/push/subscribe', ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertStatus(422);
        $this->getJson('/push/key')->assertOk()->assertJsonStructure(['key']);
    }

    // --- Scoring fixes (owner decision 2026-11-07) ---

    public function test_a_well_funded_build_with_many_bids_is_no_longer_buried_at_zero(): void
    {
        app(Settings::class)->set(['rank_rebuild' => true, 'min_budget_usd' => 30]);

        // Project 2318 (HandyHub): base 15, 85 bids, USD 250-750, large/off-niche. Was 15 - 25 - 20 = 0.
        $result = app(ScoreAdjuster::class)->adjust(
            ['fit_score' => 15, 'large_vague_or_off_niche' => true],
            ['type' => 'fixed', 'budget_min' => 250, 'budget_max' => 750, 'exchange_rate' => 1, 'bid_count' => 85, 'client' => ['payment_verified' => true]],
            'Build a Local Home-Services Marketplace (MVP)',
        );

        $this->assertSame(5, $result['score']);
        $this->assertSame([['label' => '85 bids (31+)', 'points' => -10]], $result['adjustments']);
    }

    public function test_small_budgets_keep_the_full_bid_penalty_and_strong_bases_keep_the_off_niche_penalty(): void
    {
        app(Settings::class)->set(['rank_rebuild' => true, 'min_budget_usd' => 30]);
        $adjust = fn (int $base, array $d) => app(ScoreAdjuster::class)->adjust(['fit_score' => $base, 'large_vague_or_off_niche' => true],
            array_merge(['type' => 'fixed', 'budget_min' => 30, 'budget_max' => 100, 'exchange_rate' => 1, 'bid_count' => 40, 'client' => ['payment_verified' => true]], $d));

        $this->assertSame(60 - 25 - 20, $adjust(60, [])['score']);
        $this->assertSame(60 - 25 - 20, $adjust(60, ['budget_max' => 300])['score'], 'a $30 minimum is not well funded');
        $this->assertSame(60 - 5 - 20, $adjust(60, ['budget_min' => 250, 'budget_max' => 500, 'bid_count' => 20])['score']);
        $this->assertSame(60 - 10 - 20, $adjust(60, ['type' => 'hourly', 'budget_min' => 25, 'budget_max' => 40])['score']);
        $this->assertSame(60 - 25 - 20, $adjust(60, ['type' => 'hourly', 'budget_min' => 15, 'budget_max' => 30])['score']);
        $this->assertSame(39 - 25, $adjust(39, [])['score'], 'base under 40 already counts as off-niche');
    }

    public function test_the_scoring_prompt_no_longer_penalises_builds_or_small_budgets(): void
    {
        $project = $this->makeProject();
        $this->claude->reply($this->scoringReply());

        app(Scorer::class)->score($project);

        $prompt = $this->claude->requests[0]->system[0]['text'];
        $this->assertStringContainsString('It is not a penalty', $prompt);
        $this->assertStringContainsString('custom web app', $prompt);
        $this->assertStringContainsString('Do not lower the score because the budget is small', $prompt);
        $this->assertStringNotContainsString('unrealistic budgets', $prompt);
    }
}
