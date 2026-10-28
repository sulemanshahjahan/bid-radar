<?php

namespace App\Services\Backup;

use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Database backups without Freelancer project data (API Terms: no project data older than 24 hours).
 *
 * A full copy always holds rows that are nearly 24 hours old, so even a short-lived full backup
 * breaks the rule. Instead every backup applies the purge's own rules (PurgeProjectData) to all
 * rows regardless of age: projects the purge would delete are dropped, the rest lose their
 * Freelancer fields, and bid history plus transient state are emptied. What remains is exactly
 * what BidRadar keeps long term: settings, the owner account, fact bank, drafts, bid outcomes,
 * decisions, daily counts and API call logs.
 *
 * The scrubbed copy is written with VACUUM INTO, so deleted rows and wiped values are not left
 * behind in free pages, then checked before it is kept.
 */
class ScrubbedBackup
{
    public const PREFIX = 'bidradar-scrubbed-';

    /** Project columns holding Freelancer project data (encrypted at rest, wiped by the purge). */
    public const RESTRICTED_COLUMNS = ['title', 'description', 'details', 'scoring', 'filter_reason', 'ai_error'];

    /** Emptied: Freelancer bid history, and transient state that is rebuilt (sessions, cache, queue, reset tokens). */
    public const EMPTIED_TABLES = ['bid_snapshots', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    /** Leftover temp copies older than this are removed (a crashed run); younger ones may belong to a running backup. */
    private const STALE_TEMP_MINUTES = 60;

    public function __construct(private readonly Settings $settings) {}

    /** Write a verified scrubbed backup into $dir and return its path. Nothing restricted is left behind on failure. */
    public function create(string $dir): string
    {
        $tmpDir = $dir.'/.tmp';
        File::ensureDirectoryExists($tmpDir, 0700);
        $this->removeStaleTemp($tmpDir);

        $tmp = $tmpDir.'/scrub-'.Str::random(16).'.sqlite';
        $final = $dir.'/'.self::PREFIX.now()->format('Y-m-d-His').'.sqlite';
        $pdo = null;

        try {
            DB::statement('VACUUM INTO ?', [$tmp]); // consistent snapshot while the app runs
            $pdo = $this->open($tmp);
            $this->scrub($pdo);
            $pdo->exec('VACUUM INTO '.$pdo->quote($final));
            $pdo = null;

            if ($problems = $this->verify($final)) {
                throw new RuntimeException('Scrubbed backup failed verification: '.implode('; ', $problems));
            }
            @chmod($final, 0600);

            return $final;
        } catch (Throwable $e) {
            $pdo = null;
            $this->deleteDatabaseFiles($final);

            throw $e;
        } finally {
            $pdo = null;
            $this->deleteDatabaseFiles($tmp);
        }
    }

    /**
     * Problems with a backup file: integrity, missing schema, or Freelancer project data present.
     * Empty when it is safe to keep and restore.
     *
     * @return list<string>
     */
    public function verify(string $path): array
    {
        if (! is_file($path)) {
            return ["no such file: {$path}"];
        }

        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
            ]);
            $problems = [];

            $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
            if ($integrity !== 'ok') {
                $problems[] = "integrity_check: {$integrity}";
            }
            if (! $this->hasTable($pdo, 'migrations') || ! $this->hasTable($pdo, 'projects')) {
                return [...$problems, 'not a BidRadar database (migrations or projects table missing)'];
            }
            foreach (self::RESTRICTED_COLUMNS as $column) {
                $n = (int) $pdo->query("SELECT count(*) FROM projects WHERE \"{$column}\" IS NOT NULL")->fetchColumn();
                if ($n > 0) {
                    $problems[] = "projects.{$column} set on {$n} row(s)";
                }
            }
            foreach (self::EMPTIED_TABLES as $table) {
                if ($this->hasTable($pdo, $table) && ($n = (int) $pdo->query("SELECT count(*) FROM \"{$table}\"")->fetchColumn()) > 0) {
                    $problems[] = "{$table} has {$n} row(s)";
                }
            }
            if ($this->hasTable($pdo, 'users') && (int) $pdo->query('SELECT count(*) FROM users WHERE remember_token IS NOT NULL')->fetchColumn() > 0) {
                $problems[] = 'users.remember_token set';
            }

            return $problems;
        } catch (Throwable $e) {
            return ['cannot read: '.$e->getMessage()];
        } finally {
            $pdo = null;
        }
    }

    private function scrub(PDO $pdo): void
    {
        $pdo->beginTransaction();

        // Same rule as the purge, without the age condition: untouched filtered or low-fit projects go.
        $pdo->prepare(<<<'SQL'
            DELETE FROM projects
            WHERE status = 'new'
              AND NOT EXISTS (SELECT 1 FROM drafts WHERE drafts.project_id = projects.id)
              AND (ai_state = 'filtered' OR fit_score IS NULL OR fit_score < :medium)
            SQL)->execute(['medium' => $this->settings->int('threshold_medium')]);

        $set = implode(', ', array_map(fn ($c) => "\"{$c}\" = NULL", self::RESTRICTED_COLUMNS));
        $pdo->prepare("UPDATE projects SET {$set}, purged_at = COALESCE(purged_at, :now)")
            ->execute(['now' => now()->format('Y-m-d H:i:s')]);

        foreach (self::EMPTIED_TABLES as $table) {
            if ($this->hasTable($pdo, $table)) {
                $pdo->exec("DELETE FROM \"{$table}\"");
            }
        }
        $pdo->exec('UPDATE users SET remember_token = NULL');

        $pdo->commit();
    }

    private function open(string $path): PDO
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON'); // api_calls.project_id -> null, as in the app
        $pdo->exec('PRAGMA journal_mode = DELETE');

        return $pdo;
    }

    private function hasTable(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$table]);

        return (bool) $st->fetchColumn();
    }

    private function removeStaleTemp(string $tmpDir): void
    {
        foreach (File::glob($tmpDir.'/scrub-*') as $file) {
            if (filemtime($file) < now()->subMinutes(self::STALE_TEMP_MINUTES)->getTimestamp()) {
                File::delete($file);
            }
        }
    }

    private function deleteDatabaseFiles(string $path): void
    {
        foreach ([$path, $path.'-journal', $path.'-wal', $path.'-shm'] as $file) {
            if (is_file($file)) {
                File::delete($file);
            }
        }
    }
}
