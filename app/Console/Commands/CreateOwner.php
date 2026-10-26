<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('bidradar:user {email} {--name=Owner}')]
#[Description('Create or update the single owner account (asks for the password)')]
class CreateOwner extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = (string) $this->secret('Password (min 12 characters)');

        $validator = Validator::make(compact('email', 'password'), [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (User::query()->where('email', '!=', $email)->exists()) {
            $this->error('Another account already exists. BidRadar is single-user.');

            return self::FAILURE;
        }

        User::query()->updateOrCreate(['email' => $email], [
            'name' => (string) $this->option('name'),
            'password' => $password,
        ]);

        $this->info("Owner account ready: {$email}");

        return self::SUCCESS;
    }
}
