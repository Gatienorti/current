<?php

namespace HorizonLens;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Throwable;

final class ScheduleReader
{
    public function events(): array
    {
        // Load console routes and legacy kernel schedules, but never invoke a command.
        app(Kernel::class)->bootstrap();

        return app(Schedule::class)->events();
    }

    public function describe(Event $event): array
    {
        $timezone = $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : ($event->timezone ?: config('app.timezone', 'UTC'));
        $next = null;
        try {
            $now = Carbon::now($timezone);
            $cron = new CronExpression($event->expression);
            $next = Carbon::instance($cron->getNextRunDate($now));
            if ($event->repeatSeconds && $cron->isDue($now)) {
                $next = $now->copy()->endOfSecond()->ceilSeconds($event->repeatSeconds);
            }
        } catch (Throwable) {
            // Invalid dates/expressions are unavailable, not proof of inactivity.
        }
        $flags = [];
        foreach (['withoutOverlapping' => 'Without overlapping', 'onOneServer' => 'One server', 'runInBackground' => 'Background', 'evenInMaintenanceMode' => 'During maintenance'] as $field => $label) {
            if ($event->{$field}) {
                $flags[] = $label;
            }
        }
        $command = null;
        if (! $event instanceof CallbackEvent) {
            // Never expose command arguments, shell pipelines, or environment assignments.
            $normalized = $event->normalizeCommand($event->command ?? '');
            if (preg_match('/^php artisan ([a-zA-Z0-9:_-]+)(?:\s|$)/', $normalized, $match)) {
                $command = $match[1];
            }
        }

        return [
            'name' => mb_substr($event->description ?: ($command ?: ($event instanceof CallbackEvent ? 'Callback' : 'Shell command')), 0, 240),
            'command' => $command,
            'expression' => $event->expression,
            'frequency' => $this->frequency($event),
            'timezone' => $timezone,
            'next' => $next?->format('M j, H:i:s'),
            'flags' => $flags,
            'environments' => $event->environments,
        ];
    }

    private function frequency(Event $event): string
    {
        if ($event->repeatSeconds) {
            return 'Every '.$event->repeatSeconds.' seconds';
        }
        $expression = $event->expression;
        if ($expression === '* * * * *') {
            return 'Every minute';
        }
        if (preg_match('/^\*\/(\d+) \* \* \* \*$/', $expression, $match)) {
            return 'Every '.$match[1].' minutes';
        }
        if (preg_match('/^(\d+) \* \* \* \*$/', $expression, $match)) {
            return (int) $match[1] === 0 ? 'Every hour' : 'Hourly · :'.str_pad($match[1], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(\d+) (\d+) \* \* \*$/', $expression, $match)) {
            return sprintf('Daily · %02d:%02d', $match[2], $match[1]);
        }

        if (preg_match('/^(\d+) \*\/(\d+) \* \* \*$/', $expression, $match)) {
            return 'Every '.$match[2].' hours'.((int) $match[1] === 0 ? '' : ' · :'.str_pad($match[1], 2, '0', STR_PAD_LEFT));
        }
        if (preg_match('/^(\d+) (\d+) \* \* ([0-7])$/', $expression, $match)) {
            $day = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][(int) $match[3] % 7];

            return sprintf('%ss · %02d:%02d', $day, $match[2], $match[1]);
        }
        if (preg_match('/^(\d+) (\d+) (\d+) \* \*$/', $expression, $match)) {
            return sprintf('Monthly · Day %d · %02d:%02d', $match[3], $match[2], $match[1]);
        }

        return 'Custom schedule';
    }
}
