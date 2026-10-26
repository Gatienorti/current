<?php

namespace HorizonLens\Broadcasts;

use Illuminate\Broadcasting\BroadcastManager;
use Laravel\Reverb\ReverbServiceProvider;

final class ReverbIntegration
{
    public static function visible(): bool
    {
        $override = config('horizon-lens.broadcasts.visible');
        if (is_bool($override)) {
            return $override;
        }
        $default = config('broadcasting.default');
        $driver = config("broadcasting.connections.{$default}.driver");

        return class_exists(ReverbServiceProvider::class)
            || in_array($driver, ['reverb', 'current-reverb'], true);
    }

    public static function configure(): void
    {
        if (! config('horizon-lens.enabled', true) || ! config('horizon-lens.broadcasts.enabled', false)) {
            return;
        }
        foreach ((array) config('broadcasting.connections', []) as $name => $connection) {
            if (($connection['driver'] ?? null) === 'reverb') {
                config()->set("broadcasting.connections.{$name}.driver", 'current-reverb');
                config()->set("broadcasting.connections.{$name}.current_connection", $name);
            }
        }
    }

    public static function register(BroadcastManager $manager): void
    {
        $manager->extend('current-reverb', function ($app, $config) use ($manager) {
            // Uses Laravel's existing Pusher/Reverb construction and authentication behavior.
            $driver = new ObservedReverbBroadcaster($manager->pusher($config), $config['jsonp'] ?? false);
            $driver->connectionName = (string) ($config['current_connection'] ?? 'reverb');

            return $driver;
        });
    }
}
