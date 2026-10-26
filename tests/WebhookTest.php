<?php

namespace HorizonLens\Tests;

use HorizonLens\Prototype\Jobs\PaymentJob;
use HorizonLens\Prototype\WebhookDemo;
use HorizonLens\Webhooks\WebhookRecorder;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class WebhookTest extends TestCase
{
    private function receipt(string $scenario = 'success', bool $valid = true)
    {
        $body = '{"token":"private-body-value"}';

        return $this->call('POST', '/__lens/webhooks/'.$scenario.'?secret=private-query-value', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer private-header-value',
            'HTTP_X_DEMO_SIGNATURE' => $valid ? hash_hmac('sha256', $body, WebhookDemo::SECRET) : 'bad',
        ], $body);
    }

    public function test_receipt_response_and_later_job_failure_are_distinct_and_private_data_is_not_stored(): void
    {
        $this->receipt('job-failure')->assertStatus(202)->assertJson(['accepted' => true]);
        $receipt = $this->store()->table('webhook_receipts')->first();
        $this->assertSame(202, $receipt->http_status);
        $this->assertSame('valid', $receipt->signature);
        $this->assertSame('__lens/webhooks/{scenario}', $receipt->route);
        $this->assertGreaterThanOrEqual(0, $receipt->duration_ms);
        $this->assertStringNotContainsString('private-', json_encode($receipt));
        $this->assertSame(1, $this->store()->table('webhook_jobs')->count());
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'lens-webhook-demo', '--once' => true, '--sleep' => 0, '--tries' => 1])->assertExitCode(0);
        $this->get('/horizon-lens/webhooks/'.$receipt->id)->assertOk()->assertSee('202')->assertSee('Failed')->assertSee('PaymentJob');
        $this->get('/horizon-lens/webhooks?provider=vehify-demo&status=202')->assertOk()->assertSee('vehify-demo');
        $this->get('/horizon-lens/webhooks?outcome=job_failed')->assertOk()->assertSee($receipt->id);
        $this->get('/horizon-lens/webhooks?outcome=invalid_signature')->assertOk()->assertSee('No receipts observed');
        $this->get('/horizon-lens/webhooks?status=500')->assertOk()->assertSee('No receipts observed');
    }

    public function test_invalid_signature_handler_failure_duplicates_and_disabled_collection(): void
    {
        $this->receipt('success', false)->assertStatus(401);
        $this->assertSame('invalid', $this->store()->table('webhook_receipts')->first()->signature);
        $this->get('/horizon-lens/webhooks?outcome=invalid_signature&status=401')->assertOk()->assertSee('invalid');
        $this->get('/horizon-lens/webhooks?outcome=http_error')->assertOk()->assertSee('401');
        $this->assertSame(0, $this->store()->table('webhook_jobs')->count());
        $this->receipt('handler-error')->assertStatus(500);
        $failure = $this->store()->table('webhook_receipts')->where('http_status', 500)->first();
        $this->assertSame(\RuntimeException::class, $failure->exception_class);
        $this->receipt('ignored')->assertOk();
        $this->receipt('ignored')->assertOk();
        $this->assertSame(4, $this->store()->table('webhook_receipts')->count());
        config()->set('horizon-lens.webhooks.enabled', false);
        $this->receipt('ignored')->assertOk();
        $this->assertSame(4, $this->store()->table('webhook_receipts')->count());
    }

    public function test_request_correlation_is_scoped_and_committed_jobs_only_are_linked(): void
    {
        $this->receipt()->assertStatus(202);
        $first = $this->store()->table('webhook_receipts')->first();
        $this->receipt()->assertStatus(202);
        $second = $this->store()->table('webhook_receipts')->where('id', '!=', $first->id)->first();
        $firstJob = $this->store()->table('webhook_jobs')->where('receipt_id', $first->id)->value('job_id');
        $secondJob = $this->store()->table('webhook_jobs')->where('receipt_id', $second->id)->value('job_id');
        $this->assertNotSame($firstJob, $secondJob);
        dispatch(new PaymentJob('success'));
        $this->assertSame(2, $this->store()->table('webhook_jobs')->count());
        Route::post('/__lens/rollback', function () {
            $db = app('db')->connection('app');
            $db->beginTransaction();
            dispatch((new PaymentJob('success'))->afterCommit());
            $db->rollBack();

            return response('', 202);
        })->middleware('current.webhook:transaction-demo');
        $this->post('/__lens/rollback')->assertStatus(202);
        $this->assertSame(2, $this->store()->table('webhook_jobs')->count());
        $this->get('/horizon-lens/webhooks?provider=transaction-demo&status=202')->assertOk()->assertSee('transaction-demo')->assertDontSee('vehify-demo');
        $this->get('/horizon-lens/webhooks?from=2030-01-01T00:00:00')->assertOk()->assertSee('No receipts observed');
    }

    public function test_observer_storage_failure_cannot_break_response_and_auth_and_retention_apply(): void
    {
        $this->receipt('ignored')->assertOk();
        $receipt = $this->store()->table('webhook_receipts')->first();
        $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]));
        $this->get('/horizon-lens/webhooks')->assertForbidden();
        $this->get('/horizon-lens/webhooks/'.$receipt->id)->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]));
        $this->store()->table('webhook_receipts')->where('id', $receipt->id)->update(['expires_at' => time() - 1]);
        $this->get('/horizon-lens/webhooks/'.$receipt->id)->assertNotFound();
        $this->assertSame(1, app(WebhookRecorder::class)->prune());
        Schema::connection('lens')->drop('lens_webhook_receipts');
        $this->receipt('ignored')->assertOk()->assertJson(['accepted' => false]);
    }
}
