<?php

namespace Current\Notifications;

use Current\Events\QueueWaitExceeded as WaitEvent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

/** No job payloads or dashboard links are sent; the measurement is an estimate. */
final class QueueWaitExceeded extends Notification
{
    public function __construct(
        public readonly WaitEvent $observation,
        private readonly string $channel,
        private readonly ?string $slackChannel = null,
    ) {}

    public function via($notifiable): array
    {
        return [$this->channel];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->error()
            ->subject($this->application().': Queue clearance estimate exceeded')
            ->line($this->message())
            ->line('This is an estimate from captured runtime and consuming workers, not measured job latency or a delivery guarantee.');
    }

    public function toSlack($notifiable): mixed
    {
        $route = $notifiable->routeNotificationFor('slack');
        if (is_string($route) && str_starts_with($route, 'https://')) {
            $class = \Illuminate\Notifications\Messages\SlackMessage::class;
            if (! class_exists($class)) { throw new RuntimeException('Install the Laravel Slack notification channel.'); }
            return (new $class)->from('Current')->to($this->slackChannel)->error()->content($this->slackText());
        }

        $class = \Illuminate\Notifications\Slack\SlackMessage::class;
        if (! class_exists($class)) { throw new RuntimeException('Install the Laravel Slack notification channel with channel-ID support.'); }
        return (new $class)->username('Current')->text($this->slackText())->disableMarkdownParsing();
    }

    public function toVonage($notifiable): mixed
    {
        $class = \Illuminate\Notifications\Messages\VonageMessage::class;
        if (! class_exists($class)) { throw new RuntimeException('Install the Laravel Vonage notification channel.'); }
        return (new $class)->content($this->message());
    }

    private function slackText(): string
    {
        // Slack interprets these characters even when ordinary markdown parsing is disabled.
        return strtr($this->message(), ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']);
    }

    private function message(): string
    {
        return sprintf('[%s] Queue group "%s" on connection "%s" has an estimated clearance time of %s seconds (threshold: %d seconds).',
            $this->application(), $this->label(implode(',', $this->observation->queues)),
            $this->label($this->observation->connection),
            number_format($this->observation->estimatedSeconds, 1, '.', ''), $this->observation->thresholdSeconds);
    }

    private function application(): string
    {
        return $this->label((string) config('app.name', 'Current'));
    }

    private function label(string $value): string
    {
        return mb_strcut(preg_replace('/[\x00-\x1f\x7f]/', ' ', $value), 0, 160, 'UTF-8');
    }
}
