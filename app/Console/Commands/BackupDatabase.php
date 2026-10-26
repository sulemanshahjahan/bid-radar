<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

#[Signature('bidradar:backup {--keep=14 : How many daily backups to keep}')]
#[Description('Write a consistent copy of the SQLite database to storage/app/backups')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Backups are only implemented for SQLite.');

            return self::FAILURE;
        }

        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);
        $path = $dir.'/bidradar-'.now()->format('Y-m-d-His').'.sqlite';

        // VACUUM INTO writes a consistent snapshot even while the app is running.
        DB::statement('VACUUM INTO ?', [$path]);

        $backups = collect(File::glob($dir.'/bidradar-*.sqlite'))->sort()->values();
        $backups->slice(0, max(0, $backups->count() - (int) $this->option('keep')))->each(fn ($old) => File::delete($old));

        $this->info('Backup written: '.$path);

        return self::SUCCESS;
    }
}
