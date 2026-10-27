<?php

namespace Current\Tests;

use Illuminate\Support\Facades\Artisan;

final class CollectorBootstrapTest extends TestCase
{
    public function test_internal_configuration_command_outputs_only_json_and_preserves_once(): void
    {
        config()->set('current.telemetry.driver', 'redis');
        config()->set('database.redis.default.password', 'fixture-secret');
        $this->assertSame(0, Artisan::call('current:collector-config', ['--once'=>true]));
        $settings = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($settings['once']);
        $this->assertSame('fixture-secret', $settings['password']);
        $this->assertStringNotContainsString("\033", Artisan::output());
    }

    public function test_invalid_configuration_fails_without_emitting_credentials(): void
    {
        config()->set('current.telemetry.driver', 'redis');
        config()->set('database.redis.default.password', 'fixture-secret');
        config()->set('current.telemetry.namespace', 'invalid namespace');
        $this->assertSame(1, Artisan::call('current:collector-config'));
        $this->assertStringContainsString('Collector configuration unavailable', Artisan::output());
        $this->assertStringNotContainsString('fixture-secret', Artisan::output());
    }
}
