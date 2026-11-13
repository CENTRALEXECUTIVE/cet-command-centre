<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Run work AFTER the response has gone to the browser (e.g. emails), so a slow or
 * down mail server never keeps a customer waiting. Each task runs exactly once: the
 * list is drained by a single terminating hook, so tasks never pile up and re-run
 * when one app instance serves many requests (tests, long-running workers).
 * Every task is isolated — one failing never stops the others.
 */
class AfterResponse
{
    /** @var list<\Closure> */
    private array $tasks = [];

    private bool $hooked = false;

    public static function run(\Closure $task): void
    {
        $self = app(self::class);
        $self->tasks[] = $task;

        if (! $self->hooked) {
            $self->hooked = true;
            app()->terminating(fn () => $self->drain());
        }
    }

    public function drain(): void
    {
        while ($task = array_shift($this->tasks)) {
            try {
                $task();
            } catch (\Throwable $e) {
                Log::warning('[after-response] task failed: '.$e->getMessage());
            }
        }
    }
}
