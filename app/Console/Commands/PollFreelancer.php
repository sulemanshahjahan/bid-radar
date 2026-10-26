<?php

namespace App\Console\Commands;

use App\Services\Freelancer\Poller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bidradar:poll {--dry-run : Fetch and filter, print the result, save nothing} {--now : Ignore the interval and Settings toggle (still needs FREELANCER_POLLING_ENABLED)}')]
#[Description('Fetch new active Freelancer projects, filter them, and queue scoring')]
class PollFreelancer extends Command
{
    public function handle(Poller $poller): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $poller->poll(scheduled: ! $dryRun && ! $this->option('now'), dryRun: $dryRun);

        if ($result['status'] !== 'waiting' || $this->output->isVerbose()) {
            $this->line('['.$result['status'].'] '.$result['message']);
        }

        if (! empty($result['projects']) && ($dryRun || $this->output->isVerbose())) {
            $this->table(['ID', 'Title', 'Result'], array_map(
                fn ($p) => [$p['id'], mb_strimwidth($p['title'], 0, 60, '…'), $p['result']],
                $result['projects'],
            ));
        }

        return $result['status'] === 'error' ? self::FAILURE : self::SUCCESS;
    }
}
