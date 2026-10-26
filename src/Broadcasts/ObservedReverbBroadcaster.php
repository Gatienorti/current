<?php

namespace HorizonLens\Broadcasts;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Throwable;

final class ObservedReverbBroadcaster extends PusherBroadcaster
{
    public string $connectionName = 'reverb';

    public function broadcast(array $channels, $event, array $payload = [])
    {
        $context = null;
        try {
            $context = app(BroadcastRecorder::class)->start($this->connectionName, $channels, $event, $payload);
        } catch (Throwable) {
        }
        try {
            $result = parent::broadcast($channels, $event, $payload);
        } catch (Throwable $error) {
            BroadcastRecorder::finish($context, $error);
            throw $error;
        }
        BroadcastRecorder::finish($context);

        return $result;
    }
}
