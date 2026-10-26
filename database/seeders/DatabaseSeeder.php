<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a throwaway owner for local development only.
     * In production, create the real owner with `php artisan bidradar:user you@example.com`.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('Skipped: the dev seeder only runs when APP_ENV=local.');

            return;
        }

        User::query()->updateOrCreate(['email' => 'owner@bidradar.test'], [
            'name' => 'Local Owner',
            'password' => 'local-dev-password',
        ]);
    }
}
