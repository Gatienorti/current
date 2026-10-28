<?php

namespace Current\Native;

use DateTimeInterface;
use Illuminate\Queue\Failed\DynamoDbFailedJobProvider;
use Illuminate\Support\Carbon;
use WeakMap;

/** No SDK dependency is added; this adapter loads only for an installed DynamoDB failer. */
final class DynamoFailedPayloads extends DynamoDbFailedJobProvider
{
    private static ?WeakMap $items = null;

    public static function scope(object $provider): string
    {
        return hash('sha256', $provider->table.'|'.$provider->applicationName.'|'.(string) $provider->dynamo->getEndpoint());
    }

    private static function record(array $row): object
    {
        $record = (object) [
            'id' => $row['uuid']['S'], 'connection' => $row['connection']['S'], 'queue' => $row['queue']['S'],
            'payload' => $row['payload']['S'], 'exception' => $row['exception']['S'] ?? null,
            'failed_at_timestamp' => (int) $row['failed_at']['N'],
            'failed_at' => Carbon::createFromTimestamp((int) $row['failed_at']['N'], date_default_timezone_get())->format(DateTimeInterface::ISO8601),
        ];
        self::$items ??= new WeakMap;
        self::$items[$record] = $row;

        return $record;
    }

    /** Only an opaque hash enters durable maintenance receipts; raw attributes stay in memory. */
    public static function itemHash(object $record): ?string
    {
        $item = self::$items[$record] ?? null;
        if ($item === null) {
            return null;
        }
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($canonical, $value);
        };

        return hash('sha256', json_encode($canonical($item), JSON_THROW_ON_ERROR));
    }

    public static function findRetained(object $provider, string $id): ?object
    {
        $result = $provider->dynamo->getItem(['TableName' => $provider->table, 'ConsistentRead' => true,
            'Key' => ['application' => ['S' => $provider->applicationName], 'uuid' => ['S' => $id]]]);

        return empty($result['Item']) ? null : self::record($result['Item']);
    }

    public static function forgetExact(object $provider, string $id, object $expected): bool
    {
        $item = self::$items[$expected] ?? null;
        if ($item === null) {
            return false;
        }
        if (! isset($item['exception']) && is_string($expected->exception ?? null)) {
            $item['exception'] = ['S' => $expected->exception];
        }
        $names = $values = $conditions = [];
        foreach (array_unique(array_merge(array_keys($item), ['connection', 'queue', 'payload', 'exception', 'failed_at', 'expires_at'])) as $attribute) {
            if (in_array($attribute, ['application', 'uuid'], true)) {
                continue;
            }
            $alias = '#v'.count($names);
            $names[$alias] = $attribute;
            if (array_key_exists($attribute, $item)) {
                $value = ':v'.count($values);
                $values[$value] = $item[$attribute];
                $conditions[] = $alias.' = '.$value;
            } else {
                $conditions[] = 'attribute_not_exists('.$alias.')';
            }
        }
        try {
            $provider->dynamo->deleteItem(['TableName' => $provider->table,
                'Key' => ['application' => ['S' => $provider->applicationName], 'uuid' => ['S' => $id]],
                'ConditionExpression' => implode(' AND ', $conditions), 'ExpressionAttributeNames' => $names, 'ExpressionAttributeValues' => $values]);

            return true;
        } catch (\Throwable $error) {
            if (method_exists($error, 'getAwsErrorCode') && $error->getAwsErrorCode() === 'ConditionalCheckFailedException') {
                return false;
            }
            throw $error;
        }
    }

    public static function page(object $provider, array $snapshot, mixed $cursor, int $limit): array
    {
        $input = ['TableName' => $provider->table, 'Limit' => $limit, 'ConsistentRead' => true, 'KeyConditionExpression' => 'application = :application',
            'FilterExpression' => 'failed_at < :before', 'ExpressionAttributeValues' => [':application' => ['S' => $provider->applicationName], ':before' => ['N' => (string) $snapshot['before']]]];
        if ($cursor) {
            $input['ExclusiveStartKey'] = $cursor;
        }
        $result = $provider->dynamo->query($input);
        $jobs = array_map(fn ($row) => self::record($row), $result['Items'] ?? []);

        return ['jobs' => $jobs, 'cursor' => $result['LastEvaluatedKey'] ?? null, 'complete' => empty($result['LastEvaluatedKey'])];
    }
}
