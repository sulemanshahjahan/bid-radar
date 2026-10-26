<?php

namespace App\Console\Commands;

use App\Services\Freelancer\FreelancerException;
use App\Services\Freelancer\Poller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bidradar:skills')]
#[Description('Look up the Freelancer skill (job) IDs for the target skills in Settings')]
class SyncSkills extends Command
{
    public function handle(Poller $poller): int
    {
        if (config('bidradar.freelancer.polling_enabled') !== true) {
            $this->error('FREELANCER_POLLING_ENABLED is false; no request sent.');

            return self::FAILURE;
        }

        try {
            $ids = $poller->skillIds(refresh: true);
        } catch (FreelancerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Skill', 'Freelancer ID'], collect($ids)->map(fn ($id, $name) => [$name, $id])->values()->all());
        foreach ($poller->unmatchedSkills() as $name) {
            $this->warn("No Freelancer skill named \"{$name}\". Check the spelling in Settings.");
        }

        return self::SUCCESS;
    }
}
