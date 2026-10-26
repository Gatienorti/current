<?php

namespace HorizonLens\Prototype;

use HorizonLens\Prototype\Jobs\OutgoingWebhookJob;
use HorizonLens\Prototype\Jobs\PaymentJob;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

final class WebhookDemo
{
    public const SECRET = 'current-synthetic-webhook-only';

    public static function register(): void
    {
        Route::post('/__lens/webhooks/{scenario}', function (Request $request, string $scenario) {
            $valid = hash_equals(hash_hmac('sha256', $request->getContent(), WebhookDemo::SECRET), (string) $request->header('X-Demo-Signature'));
            $request->attributes->set('current.webhook.signature', $valid ? 'valid' : 'invalid');
            abort_unless($valid, 401);
            $request->attributes->set('current.webhook.group', ['event_id' => 'evt-demo-'.$scenario, 'scope' => 'synthetic-demo-account']);
            $request->attributes->set('current.webhook.context', [
                'event_id' => 'evt-demo-'.$scenario, 'event_type' => 'order.updated',
                'payload' => ['order_id' => 'demo-1042', 'status' => $scenario, 'token' => 'never-store-demo-token'],
                'metadata' => ['source' => 'synthetic provider', 'signature' => 'private-signature'],
            ]);
            if ($scenario === 'handler-error') {
                throw new \RuntimeException('Synthetic webhook handler failure');
            }
            if ($scenario === 'ignored') {
                return response()->json(['accepted' => false], 200);
            }
            $job = new PaymentJob($scenario === 'job-failure' ? 'failure' : 'success', 'webhook-demo');
            $job->tries = 1;
            $job->onQueue('lens-webhook-demo');
            dispatch($job);

            return response()->json(['accepted' => true], 202);
        })->middleware('current.webhook:vehify-demo');

        Route::post('/__lens/webhook-flow', function () {
            dispatch(new OutgoingWebhookJob('retry'));

            return response()->json(['accepted' => true], 202);
        })->middleware('current.webhook:flow-demo');
        Artisan::command('lens:webhook-flow-demo', function () {
            config()->set('horizon-lens.webhooks.capture_context', true);
            $request = Request::create('/__lens/webhook-flow', 'POST');
            $kernel = app(Kernel::class);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
            app('queue.worker')->runNextJob('redis', 'lens-outgoing-demo', new WorkerOptions(sleep: 0, maxTries: 1));
            $this->info('Synthetic receipt → job → two HTTP attempts observed. No external calls.');
        });

        Artisan::command('lens:webhook-demo', function () {
            config()->set('horizon-lens.webhooks.capture_context', true);
            foreach (['success', 'job-failure', 'invalid-signature', 'handler-error', 'ignored', 'success'] as $scenario) {
                $body = '{"event":"synthetic.order","token":"never-store-demo-token"}';
                $request = Request::create('/__lens/webhooks/'.$scenario.'?secret=never-store-query', 'POST', [], [], [], [
                    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                    'HTTP_X_DEMO_SIGNATURE' => $scenario === 'invalid-signature' ? 'invalid' : hash_hmac('sha256', $body, WebhookDemo::SECRET),
                ], $body);
                $kernel = app(Kernel::class);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                $this->line($scenario.': HTTP '.$response->getStatusCode());
            }
            for ($i = 0; $i < 3; $i++) {
                app('queue.worker')->runNextJob('redis', 'lens-webhook-demo', new WorkerOptions(sleep: 0, maxTries: 1));
            }
            $this->info('Six synthetic receipts and three job outcomes recorded. No provider calls.');
        });
    }
}
