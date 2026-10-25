<?php

namespace HorizonLens\Tests;

use Current\Current;
use HorizonLens\Prototype\Jobs\PaymentJob;
use HorizonLens\Support\JobContext;
use HorizonLens\Support\PayloadData;
use Illuminate\Contracts\Database\ModelIdentifier;

final class AutomaticContextTest extends TestCase
{
    public function test_ordinary_job_data_and_policy_are_automatic(): void
    {
        dispatch(new PaymentJob('success', 'automatic-order'));
        $record = $this->store()->table('jobs')->first();
        $this->assertSame('captured', $record->arguments_state);
        $this->assertSame('automatic-order', json_decode($record->arguments_json, true)['order']);
        $this->assertSame($record->external_id, json_decode($record->metadata_json, true)['uuid']);
        $this->work();
        $this->get('/horizon-lens/jobs/'.$record->id)->assertOk()->assertSee('automatic-order');
    }

    public function test_decoder_never_constructs_objects_and_preserves_model_identifiers_as_data(): void
    {
        $payload = serialize((object) ['password' => 'hidden', 'model' => new ModelIdentifier('MissingModel', 42, [], 'mysql')]);
        $context = app(JobContext::class)->capture(new \stdClass, ['data' => ['command' => $payload]]);
        $value = json_decode($context['arguments_json'], true);
        $this->assertSame('[REDACTED]', $value['password']);
        $this->assertSame(42, $value['model']['properties']['id']);
        $data = 'O:17:"NonexistentClassX":1:{s:5:"token";s:6:"secret";}';
        $autoloads = [];
        $loader = function ($class) use (&$autoloads) {
            $autoloads[] = $class;
        };
        spl_autoload_register($loader);
        try {
            $this->assertSame(['token' => 'secret'], (new PayloadData($data))->read());
            $this->assertSame([], $autoloads);
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function test_encrypted_malformed_closure_and_oversized_payloads_are_explicitly_unavailable(): void
    {
        foreach (['encrypted-opaque-data', 'O:99:"short"', 'O:17:"CallQueuedClosure":0:{}'] as $command) {
            $result = app(JobContext::class)->capture(new \stdClass, ['data' => ['command' => $command]]);
            $this->assertSame('unavailable', $result['arguments_state']);
            $this->assertNull($result['arguments_json']);
        }
        $result = app(JobContext::class)->capture(new \stdClass, ['data' => ['command' => 'O:'.str_repeat('a', 262144)]]);
        $this->assertSame('size_limit', $result['arguments_state']);
    }

    public function test_references_and_private_names_are_read_without_execution_and_redacted(): void
    {
        $object = new class
        {
            private string $apiToken = 'never-store-me';

            public array $order = ['id' => 4];
        };
        // Anonymous PHP objects cannot be serialized; use the exact wire format for private names.
        $key = "\0Example\0apiToken";
        $command = 'O:7:"Example":2:{'.serialize($key).serialize('never-store-me').'s:4:"self";r:1;}';
        $result = app(JobContext::class)->capture($object, ['data' => ['command' => $command]]);
        $this->assertStringNotContainsString('never-store-me', $result['arguments_json']);
        $this->assertStringContainsString('REFERENCE OMITTED', $result['arguments_json']);
    }

    public function test_current_methods_override_automatic_values_and_work_without_a_payload(): void
    {
        $job = new class
        {
            public function currentArguments(): array
            {
                return ['safe_id' => 12];
            }

            public function currentMetadata(): array
            {
                return [];
            }
        };
        $result = app(JobContext::class)->capture($job, ['data' => ['command' => 'encrypted']]);
        $this->assertSame('{"safe_id":12}', $result['arguments_json']);
        $this->assertSame('[]', $result['metadata_json']);
        $this->assertSame(123, Current::step('Outside worker', fn () => 123));
    }
}
