<?php

namespace App\Console\Commands;

use App\Services\FailureAlerts;
use App\Services\Freelancer\FreelancerClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bidradar:watchdog')]
#[Description('Alert on Telegram if polling is on but nothing has polled successfully for 30 minutes')]
class Watchdog extends Command
{
    public function handle(FailureAlerts $alerts, FreelancerClient $freelancer): int
    {
        if ($alerts->checkPolling($freelancer)) {
            $this->warn('Polling stalled: alert sent.');
        }

        return self::SUCCESS;
    }
}
