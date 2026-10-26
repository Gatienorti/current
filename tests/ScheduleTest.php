<?php

namespace Current\Tests;

use Current\ScheduleReader;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

final class ScheduleTest extends TestCase
{
    public function test_schedule_is_read_only_hides_arguments_and_preserves_timezone_and_options(): void
    {
        Carbon::setTestNow('2026-10-26 12:00:01 UTC');
        try {
            $schedule = new Schedule('UTC');
            $executed = false;
            $schedule->call(function () use (&$executed) {
                $executed = true;
            })
                ->name('Daily report')->dailyAt('09:30')->timezone('America/New_York')
                ->withoutOverlapping()->onOneServer()->when(function () {
                    throw new \RuntimeException('Must not evaluate');
                });
            $schedule->command('reports:send', ['--token' => 'hidden-secret'])->everyFiveMinutes()->runInBackground();
            $this->app->instance(Schedule::class, $schedule);
            $response = $this->get('/current/schedule')->assertOk()->assertSee('Daily report')
                ->assertSee('reports:send')->assertDontSee('hidden-secret')->assertSee('Without overlapping')
                ->assertSee('One server')->assertSee('Background')->assertSee('Daily · 09:30')->assertSee('Every 5 minutes');
            $rows = $response->viewData('tasks')->items();
            $this->assertSame('America/New_York', $rows[0]['timezone']);
            $this->assertSame('Oct 26, 09:30:00', $rows[0]['next']);
            $this->assertFalse($executed);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_subminute_invalid_and_paginated_schedules(): void
    {
        Carbon::setTestNow('2026-10-26 12:00:11 UTC');
        try {
            $schedule = new Schedule('UTC');
            $event = $schedule->call(fn () => null)->everyTenSeconds();
            $this->assertSame('Oct 26, 12:00:20', app(ScheduleReader::class)->describe($event)['next']);
            $event->expression = 'invalid';
            $this->assertNull(app(ScheduleReader::class)->describe($event)['next']);
            for ($i = 1; $i <= 25; $i++) {
                $schedule->call(fn () => null)->name('Task '.$i);
            }
            $this->app->instance(Schedule::class, $schedule);
            $first = $this->get('/current/schedule')->assertOk();
            $this->assertCount(25, $first->viewData('tasks')->items());
            $second = $this->get('/current/schedule?page=2')->assertOk()->assertSee('Task 25');
            $this->assertCount(1, $second->viewData('tasks')->items());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_unavailable_is_distinct_from_an_empty_schedule(): void
    {
        $this->app->instance(Schedule::class, new Schedule('UTC'));
        $this->get('/current/schedule')->assertOk()->assertSee('No scheduled tasks');
        $this->app->forgetInstance(Schedule::class);
        $this->app->bind(Schedule::class, fn () => throw new \RuntimeException('secret-config'));
        $this->get('/current/schedule')->assertOk()->assertSee('Schedule unavailable')->assertDontSee('secret-config');
    }
}
