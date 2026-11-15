<?php

namespace App\Console\Commands;

use App\Services\FailureAlerts;
use App\Services\LockRecovery;
use App\Services\Freelancer\FreelancerClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bidradar:watchdog')]
#[Description('Alert on Telegram if polling has stalled for 30 minutes, and re-queue projects that failed only because of a database lock')]
class Watchdog extends Command
{
    public function handle(FailureAlerts $alerts, FreelancerClient $freelancer, LockRecovery $locks): int
    {
        if ($alerts->checkPolling($freelancer)) {
            $this->warn('Polling stalled: alert sent.');
        }

        if ($ids = $locks->requeue()) {
            $this->info('Re-queued after a database lock: '.implode(', ', $ids));
        }

        return self::SUCCESS;
    }
}
