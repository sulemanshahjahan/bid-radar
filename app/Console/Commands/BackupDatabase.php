<?php

namespace App\Console\Commands;

use App\Services\Backup\ScrubbedBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Daily backup without Freelancer project data (ScrubbedBackup). Full copies are never written:
 * they would always hold project data close to 24 hours old.
 */
#[Signature('bidradar:backup {--keep=14 : How many scrubbed backups to keep} {--verify= : Only check an existing backup file (integrity, no Freelancer project data)}')]
#[Description('Write a scrubbed copy of the SQLite database (no Freelancer project data) to storage/app/backups')]
class BackupDatabase extends Command
{
    public function handle(ScrubbedBackup $backups): int
    {
        if ($file = $this->option('verify')) {
            $problems = $backups->verify($file);
            foreach ($problems as $problem) {
                $this->error($problem);
            }
            if ($problems !== []) {
                return self::FAILURE;
            }
            $this->info("OK: {$file} is intact and holds no Freelancer project data.");

            return self::SUCCESS;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Backups are only implemented for SQLite.');

            return self::FAILURE;
        }

        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        try {
            $path = $backups->create($dir);
        } catch (Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $scrubbed = collect(File::glob($dir.'/'.ScrubbedBackup::PREFIX.'*.sqlite'))->sort()->values();
        $scrubbed->slice(0, max(0, $scrubbed->count() - (int) $this->option('keep')))->each(fn ($old) => File::delete($old));

        $this->info('Scrubbed backup written: '.$path);

        // Older full backups are never deleted automatically: list them for a deliberate clean-up.
        $full = collect(File::glob($dir.'/bidradar-*.sqlite'))->reject(fn ($f) => str_starts_with(basename($f), ScrubbedBackup::PREFIX))->values();
        if ($full->isNotEmpty()) {
            $this->warn($full->count().' full backup(s) still contain Freelancer project data and should be deleted once a scrubbed backup is verified:');
            $full->each(fn ($f) => $this->line('  '.$f));
        }

        return self::SUCCESS;
    }
}
