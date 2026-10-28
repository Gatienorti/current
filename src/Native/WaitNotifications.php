<?php

namespace Current\Native;

use Current\Events\QueueWaitExceeded;
use Current\ExecutionBackend;
use Current\Notifications\QueueWaitExceeded as WaitNotification;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Throwable;

/** Delivery runs only in the separate wait monitor, after its shared cooldown claim. */
final class WaitNotifications
{
    public function handle(QueueWaitExceeded $event): void
    {
        if (! ExecutionBackend::native() || ! config('current.enabled', true)
            || config('current.native.notifications.enabled', false) !== true) {
            return;
        }

        foreach (['mail', 'slack', 'sms'] as $kind) {
            try {
                [$channel, $route] = $this->route($kind);
                if ($route === null) { continue; }
                // Deliberately synchronous: alerts must not queue work into the congested queue.
                // Each channel gets a separate send so one failed transport cannot suppress others.
                Notification::route($channel, $route)->notifyNow(new WaitNotification(
                    $event, $channel, $kind === 'slack' ? config('current.native.notifications.slack_channel') : null,
                ));
            } catch (Throwable $exception) {
                $this->failed($kind, $exception);
            }
        }
    }

    private function route(string $kind): array
    {
        $route = config('current.native.notifications.'.$kind);
        $channel = $kind === 'sms' ? 'vonage' : $kind;
        if ($route === null || $route === '' || $route === []) { return [$channel, null]; }

        if ($kind === 'mail') {
            $addresses = is_string($route) ? [$route] : $route;
            if (! is_array($addresses) || ! array_is_list($addresses) || count($addresses) > 10) {
                throw new InvalidArgumentException('Native notification mail routing requires at most ten addresses.');
            }
            foreach ($addresses as $address) {
                if (! is_string($address) || strlen($address) > 254 || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Invalid native notification mail address.');
                }
            }
        } elseif ($kind === 'slack') {
            // Channel IDs use the host's Slack credentials; webhooks use only their configured HTTPS URL.
            if (! is_string($route) || strlen($route) > 2048 || ! (
                preg_match('/^[CGD][A-Z0-9]{1,79}$/D', $route)
                || (filter_var($route, FILTER_VALIDATE_URL) && parse_url($route, PHP_URL_SCHEME) === 'https'
                    && ! parse_url($route, PHP_URL_USER) && ! parse_url($route, PHP_URL_PASS)
                    && ! preg_match('/[\x00-\x20\x7f]/', $route))
            )) {
                throw new InvalidArgumentException('Native Slack routing requires an HTTPS webhook or channel ID.');
            }
            $legacyChannel = config('current.native.notifications.slack_channel');
            if ($legacyChannel !== null && (! is_string($legacyChannel) || strlen($legacyChannel) > 80
                || ! preg_match('/^[@#]?[a-zA-Z0-9_-]{1,79}$/D', $legacyChannel))) {
                throw new InvalidArgumentException('Invalid native Slack webhook channel.');
            }
        } elseif (! is_string($route) || ! preg_match('/^\+?[0-9]{6,15}$/D', $route)) {
            throw new InvalidArgumentException('Native SMS routing requires a phone number and a supported channel.');
        }

        return [$channel, $route];
    }

    private function failed(string $channel, Throwable $exception): void
    {
        try {
            // Never log recipients, webhook credentials or transport exception messages.
            app('log')->warning('Current native wait notification could not be delivered.', [
                'channel' => $channel, 'error_class' => $exception::class,
            ]);
        } catch (Throwable) {
            // A broken diagnostic transport must not interrupt other channels or event listeners.
        }
    }
}
