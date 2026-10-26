<?php

namespace Current\Tests;

use Current\Prototype\Jobs\PaymentJob;
use Current\Webhooks\ReceiptGroups;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

final class ReceiptGroupingTest extends TestCase
{
    private function route(string $path = '/__group', string $provider = 'test'): void
    {
        Route::post($path, function (Request $request) {
            $request->attributes->set('current.webhook.signature', $request->input('signature', 'valid'));
            $request->attributes->set('current.webhook.group', $request->input('group'));
            if ($request->boolean('dispatch')) {
                dispatch(new PaymentJob('success', 'receipt-group'));
            }

            return response('original response', (int) $request->input('status', 202));
        })->middleware('current.webhook:'.$provider);
    }

    public function test_repeated_receipts_group_with_history_filters_and_per_receipt_jobs(): void
    {
        $this->route();
        $identity = ['event_id' => 'private-event', 'scope' => 'private-account'];
        $this->post('/__group', ['group' => $identity, 'status' => 503, 'dispatch' => true])->assertStatus(503)->assertSee('original response');
        $this->post('/__group', ['group' => $identity])->assertStatus(202);
        $rows = $this->store()->table('webhook_receipts')->orderBy('id')->get();
        $this->assertSame($rows[0]->receipt_key, $rows[1]->receipt_key);
        $this->assertNotNull($rows[0]->receipt_key);
        $this->assertStringNotContainsString('private-event', json_encode($rows));
        $this->assertStringNotContainsString('private-account', json_encode($rows));
        $job = $this->store()->table('jobs')->first();
        $this->get('/current/webhooks')->assertOk()->assertSee('2 receipts')->assertDontSee($rows[0]->id)->assertSee($rows[1]->id);
        $this->get('/current/webhooks?status=503&provider=test')->assertOk()->assertSee($rows[1]->id)->assertSee('1 HTTP errors');
        $this->get('/current/webhooks?status=503&provider=other')->assertOk()->assertSee('No receipts observed');
        $this->get('/current/webhooks/'.$rows[1]->id)->assertOk()->assertSee($rows[0]->id)->assertSee($job->id)->assertSee('503')->assertSee('Selected')->assertSee('No retained job dispatches');
        $this->get('/current/webhooks/'.$rows[0]->id)->assertOk()->assertSee($job->external_id);
        $this->store()->table('webhook_receipts')->where('id', $rows[0]->id)->update(['expires_at' => time() - 1]);
        $this->get('/current/webhooks?status=503')->assertOk()->assertSee('No receipts observed');
        $this->get('/current/webhooks/'.$rows[1]->id)->assertOk()->assertDontSee($rows[0]->id)->assertDontSee($job->id);
        $this->get('/current/webhooks/'.$rows[0]->id)->assertNotFound();
    }

    public function test_identity_is_scoped_and_missing_invalid_or_untrusted_identities_stay_independent(): void
    {
        $this->route();
        $this->route('/__other');
        $identity = ['event_id' => 'same', 'scope' => 'account-a'];
        foreach ([['group' => $identity], ['group' => ['event_id' => 'same', 'scope' => 'account-b']], [], ['group' => ['event_id' => 'same']], ['group' => ['event_id' => '', 'scope' => 'a']], ['group' => ['event_id' => str_repeat('a', 161), 'scope' => 'a']], ['group' => $identity, 'signature' => 'invalid']] as $input) {
            $this->post('/__group', $input)->assertStatus(202);
        }
        $this->post('/__other', ['group' => $identity])->assertStatus(202);
        $rows = $this->store()->table('webhook_receipts')->get();
        $this->assertCount(3, $rows->pluck('receipt_key')->filter()->unique());
        $this->assertSame(5, $rows->whereNull('receipt_key')->count());
        $this->assertCount(8, app(ReceiptGroups::class)->listing($this->store()->table('webhook_receipts'))->get());
    }

    public function test_latest_received_order_preserves_unknown_completion_and_limits_history(): void
    {
        $this->route();
        $this->post('/__group', ['group' => ['event_id' => 'same', 'scope' => 'a']]);
        $row = (array) $this->store()->table('webhook_receipts')->first();
        $firstId = $row['id'];
        $this->store()->table('webhook_receipts')->where('id', $firstId)->update(['finished_at' => null, 'http_status' => null]);
        for ($i = 0; $i < 101; $i++) {
            $row['id'] = (string) Str::ulid();
            $this->store()->table('webhook_receipts')->insert($row);
        }
        $this->get('/current/webhooks?outcome=unfinished')->assertOk()->assertSee($row['id'])->assertSee('Completion not observed for 1');
        $this->get('/current/webhooks/'.$row['id'])->assertOk()->assertSee('Latest 100 of 102 retained receipts')->assertDontSee($firstId);
    }
}
