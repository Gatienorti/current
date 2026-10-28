<?php

namespace Current\Tests;

use Aws\DynamoDb\DynamoDbClient;
use Current\Native\FailedPayloads;
use Current\Native\MaintenanceException;
use Illuminate\Queue\Failed\DynamoDbFailedJobProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\TestCase;

/** Opt-in real SDK acceptance. Dummy credentials and a local-only endpoint are mandatory. */
final class NativeDynamoIntegrationTest extends TestCase
{
    private ?DynamoDbClient $client = null;
    private ?string $table = null;
    private DynamoDbFailedJobProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $endpoint = getenv('CURRENT_DYNAMO_LOCAL_ENDPOINT');
        if (! $endpoint) { $this->markTestSkipped('Requires disposable DynamoDB Local and the optional AWS SDK.'); }
        if (! class_exists(DynamoDbClient::class)) { $this->fail('Install the optional AWS SDK in an isolated test consumer.'); }
        $url = parse_url($endpoint);
        $host = $url['host'] ?? '';
        $this->assertTrue(($url['scheme'] ?? '') === 'http' && ! isset($url['user']) && ! isset($url['pass'])
            && (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || preg_match('/^current-dynamo-local-[a-z0-9-]+$/D', $host)),
            'This test refuses nonlocal and AWS endpoints.');
        $this->client = new DynamoDbClient(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => $endpoint,
            'credentials' => ['key' => 'LOCALTESTONLY', 'secret' => 'localtestonly'], 'retries' => 0,
            'http' => ['connect_timeout' => 1, 'timeout' => 5]]);
        $name = 'current_failed_'.bin2hex(random_bytes(8));
        $this->client->createTable(['TableName' => $name, 'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [['AttributeName' => 'application', 'AttributeType' => 'S'], ['AttributeName' => 'uuid', 'AttributeType' => 'S']],
            'KeySchema' => [['AttributeName' => 'application', 'KeyType' => 'HASH'], ['AttributeName' => 'uuid', 'KeyType' => 'RANGE']]]);
        $this->table = $name;
        $this->provider = new DynamoDbFailedJobProvider($this->client, 'current-local-acceptance', $name);
    }

    protected function tearDown(): void
    {
        if ($this->client !== null) { Date::setTestNow(); }
        try { if ($this->table !== null) { $this->client->deleteTable(['TableName' => $this->table]); } }
        finally { parent::tearDown(); }
    }

    public function test_real_query_paging_follows_empty_filtered_pages_and_isolates_snapshot_time_and_application(): void
    {
        $old = time() - 5;
        for ($i = 3; $i <= 7; $i++) { $this->log($i, $old); }
        $other = new DynamoDbFailedJobProvider($this->client, 'another-local-application', $this->table);
        $this->log(8, $old, provider: $other);
        $snapshot = FailedPayloads::snapshot($this->provider);
        $this->assertGreaterThan($old, $snapshot['before']);
        $this->assertGreaterThanOrEqual($snapshot['before'], time());
        // Sort these excluded records first. DynamoDB applies Limit before FilterExpression.
        $this->log(1, $snapshot['before']); $this->log(2, $snapshot['before']);
        $first = FailedPayloads::page($this->provider, $snapshot, null, 2);
        $this->assertSame([], $first['jobs']); $this->assertFalse($first['complete']); $this->assertIsArray($first['cursor']);
        $ids = []; $cursor = $first['cursor']; $pages = 0;
        do {
            $page = FailedPayloads::page($this->provider, $snapshot, $cursor, 2);
            $this->assertLessThanOrEqual(2, count($page['jobs']));
            foreach ($page['jobs'] as $job) {
                $ids[] = $job->id; $this->assertSame($old, $job->failed_at_timestamp);
                $this->assertEquals($job, FailedPayloads::findSnapshot($this->provider,
                    ['id' => $job->id, 'fingerprint' => FailedPayloads::fingerprint($job)]),
                    'Query and GetItem must agree despite attribute ordering.');
            }
            $cursor = $page['cursor'];
            $this->assertLessThan(10, ++$pages);
        } while (! $page['complete']);
        $this->assertSame(array_map($this->uuid(...), range(3, 7)), $ids);
        $this->assertNotNull(FailedPayloads::findRetained($this->provider, $this->uuid(1)));
        $this->expectException(MaintenanceException::class);
        FailedPayloads::page($other, $snapshot, null, 2);
    }

    public function test_real_conditional_delete_preserves_changed_payload_routing_and_failure_time(): void
    {
        $old = time() - 5;
        foreach ([['version' => 2], ['connection' => 'Redis'], ['queue' => 'ORDERS'], ['failed_at' => $old + 1]] as $replacement) {
            $this->log(10, $old);
            $expected = FailedPayloads::findRetained($this->provider, $this->uuid(10));
            $this->assertNotNull($expected);
            $snapshot = ['id' => $expected->id, 'fingerprint' => FailedPayloads::fingerprint($expected)];
            $this->assertEquals($expected, FailedPayloads::findSnapshot($this->provider, $snapshot));
            $this->log(10, $replacement['failed_at'] ?? $old, $replacement['version'] ?? 1,
                $replacement['connection'] ?? 'redis', $replacement['queue'] ?? 'orders');
            $this->assertFalse(FailedPayloads::forgetExact($this->provider, $expected->id, $expected));
            $retained = FailedPayloads::findRetained($this->provider, $expected->id);
            $this->assertNotNull($retained);
            $this->assertNotSame(FailedPayloads::fingerprint($expected), FailedPayloads::fingerprint($retained));
            try { FailedPayloads::findSnapshot($this->provider, $snapshot); $this->fail('Changed snapshot must not resolve to its replacement.'); }
            catch (MaintenanceException $error) { $this->assertStringContainsString('replacement was preserved', $error->getMessage()); }
        }
        $this->log(10, $old);
        $expected = FailedPayloads::findRetained($this->provider, $this->uuid(10));
        $this->assertTrue(FailedPayloads::forgetExact($this->provider, $expected->id, $expected));
        $this->assertNull(FailedPayloads::findRetained($this->provider, $expected->id));
        $this->assertFalse(FailedPayloads::forgetExact($this->provider, $expected->id, $expected));
    }

    public function test_existing_same_second_failure_is_included_but_post_snapshot_failure_is_excluded(): void
    {
        $this->log(20, time());
        $snapshot = FailedPayloads::snapshot($this->provider);
        $this->log(21, time());
        $page = FailedPayloads::page($this->provider, $snapshot, null, 100);
        $this->assertSame([$this->uuid(20)], array_map(fn ($job) => $job->id, $page['jobs']));
        $this->assertTrue($page['complete']);
        $this->assertNotNull(FailedPayloads::findRetained($this->provider, $this->uuid(21)));
    }

    public function test_same_second_replacement_with_changed_exception_is_preserved(): void
    {
        $this->log(30, time() - 1);
        $expected = FailedPayloads::findRetained($this->provider, $this->uuid(30));
        $this->client->updateItem(['TableName' => $this->table,
            'Key' => ['application' => ['S' => 'current-local-acceptance'], 'uuid' => ['S' => $this->uuid(30)]],
            'UpdateExpression' => 'SET #e = :exception', 'ExpressionAttributeNames' => ['#e' => 'exception'],
            'ExpressionAttributeValues' => [':exception' => ['S' => 'Synthetic replacement failure in the same second']]]);
        $this->assertFalse(FailedPayloads::forgetExact($this->provider, $expected->id, $expected));
        $this->assertNotNull(FailedPayloads::findRetained($this->provider, $expected->id));
    }

    public function test_ttl_replacement_and_newly_added_failure_evidence_are_preserved(): void
    {
        $this->log(31, time() - 1);
        $key = ['application' => ['S' => 'current-local-acceptance'], 'uuid' => ['S' => $this->uuid(31)]];
        $expected = FailedPayloads::findRetained($this->provider, $this->uuid(31));
        $this->client->updateItem(['TableName' => $this->table, 'Key' => $key, 'UpdateExpression' => 'SET #ttl = :ttl',
            'ExpressionAttributeNames' => ['#ttl' => 'expires_at'], 'ExpressionAttributeValues' => [':ttl' => ['N' => (string) (time() + 8 * 86400)]]]);
        $this->assertFalse(FailedPayloads::forgetExact($this->provider, $expected->id, $expected));
        // Older imported provider items may omit Laravel's optional error/TTL attributes.
        $this->client->updateItem(['TableName' => $this->table, 'Key' => $key, 'UpdateExpression' => 'REMOVE #e, #ttl',
            'ExpressionAttributeNames' => ['#e' => 'exception', '#ttl' => 'expires_at']]);
        $expected = FailedPayloads::findRetained($this->provider, $this->uuid(31));
        $this->client->updateItem(['TableName' => $this->table, 'Key' => $key, 'UpdateExpression' => 'SET #e = :e',
            'ExpressionAttributeNames' => ['#e' => 'exception'], 'ExpressionAttributeValues' => [':e' => ['S' => 'Synthetic newly recorded failure']]]);
        $this->assertFalse(FailedPayloads::forgetExact($this->provider, $expected->id, $expected));
        $this->assertNotNull(FailedPayloads::findRetained($this->provider, $expected->id));
    }

    private function uuid(int $number): string { return sprintf('%08d-0000-4000-8000-%012d', $number, $number); }

    private function log(int $number, int $failedAt, int $version = 1, string $connection = 'redis', string $queue = 'orders', ?DynamoDbFailedJobProvider $provider = null): void
    {
        Date::setTestNow(Carbon::createFromTimestamp($failedAt));
        try {
            $id = ($provider ?? $this->provider)->log($connection, $queue,
                json_encode(['uuid' => $this->uuid($number), 'version' => $version, 'data' => ['commandName' => 'SyntheticDynamoJob']], JSON_THROW_ON_ERROR),
                new \RuntimeException('Synthetic local failure'));
            $this->assertSame($this->uuid($number), $id);
        } finally { Date::setTestNow(); }
    }
}
