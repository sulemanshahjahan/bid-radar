<?php

namespace App\Jobs\Concerns;

use Throwable;

/**
 * SQLite allows one writer at a time. When the web app, the scheduler and the queue worker
 * write at the same moment, a write can still fail with "database is locked" after the busy
 * timeout. That is not a real failure: the job is released and run again a few seconds later
 * (up to $tries) instead of marking the project as failed. Any other error fails the job at
 * once, as before, so nothing is alerted or sent to Claude twice.
 */
trait RetriesDatabaseLocks
{
    public static function isDatabaseLock(?Throwable $e): bool
    {
        for (; $e !== null; $e = $e->getPrevious()) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'database is locked') || str_contains($message, 'database table is locked')) {
                return true;
            }
        }

        return false;
    }

    protected function retryingDatabaseLocks(callable $run): void
    {
        try {
            $run();
        } catch (Throwable $e) {
            if ($this->job === null) {
                throw $e; // run directly (dispatchSync in tests, artisan): nothing to release
            }
            if (self::isDatabaseLock($e) && $this->attempts() < $this->tries) {
                $this->release(3 * $this->attempts());

                return;
            }
            $this->fail($e);
        }
    }
}
