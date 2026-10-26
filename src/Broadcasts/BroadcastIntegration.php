<?php

namespace HorizonLens\Broadcasts;

use Illuminate\Broadcasting\BroadcastManager;
use Laravel\Reverb\ReverbServiceProvider;

final class BroadcastIntegration
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
            || in_array($driver, ['reverb', 'pusher', 'current-reverb', 'current-pusher'], true);
    }

    public static function configure(): void
    {
        if (! config('horizon-lens.enabled', true) || ! config('horizon-lens.broadcasts.enabled', false)) {
            return;
        }
        foreach ((array) config('broadcasting.connections', []) as $name => $connection) {
            if (in_array($connection['driver'] ?? null, ['reverb', 'pusher'], true)) {
                config()->set("broadcasting.connections.{$name}.driver", 'current-'.$connection['driver']);
                config()->set("broadcasting.connections.{$name}.current_connection", $name);
            }
        }
    }

    public static function register(BroadcastManager $manager): void
    {
        foreach (['reverb', 'pusher'] as $source) {
            $manager->extend('current-'.$source, function ($app, $config) use ($manager, $source) {
                // Keep Laravel's SDK construction, options, and channel authorization behavior.
                $driver = new ObservedPusherBroadcaster($manager->pusher($config), $config['jsonp'] ?? false);
                $driver->connectionName = (string) ($config['current_connection'] ?? $source);

                return $driver;
            });
        }
    }
}
