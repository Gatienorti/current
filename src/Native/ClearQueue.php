<?php

namespace Current\Native;

use Current\Support\DispatchIdentity;
use RuntimeException;

/** Atomic waiting-queue clear with a recoverable, bounded Redis operation receipt. */
final class ClearQueue
{
    public function run(string $connection, string $queue, string $operation, bool $resume): array
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $connection) || ! preg_match('/^[a-zA-Z0-9_.:{}-]{1,100}$/D', $queue)
            || ! DispatchIdentity::fromPayload(['_current_dispatch_id' => $operation])
            || config('queue.connections.'.$connection.'.driver') !== 'current-redis'
            || ! in_array($connection, config('current.queue_connections', []), true)) { throw new RuntimeException('Choose an explicitly observed native connection and one queue.'); }
        app(Maintenance::class)->assertCollectorCompatible();
        $driver = app('queue')->connection($connection);
        $name = config('queue.connections.'.$connection.'.connection', 'default');
        $redis = ObservationRedis::connect($name);
        $key = 'current:clear:'.hash('sha256', $connection.'|'.$queue).':'.$operation;
        $ready = $driver->getQueue($queue);
        $raw = $redis->eval(<<<'LUA'
local old=redis.call('GET',KEYS[5])
if old then return old end
if ARGV[4]=='1' then return redis.error_reply('operation receipt unavailable') end
local n=redis.call('LLEN',KEYS[1])+redis.call('ZCARD',KEYS[2])
local reserved=redis.call('ZCARD',KEYS[3])
if n+reserved>1000 then return redis.error_reply('maintenance queue bound exceeded') end
local bytes=0
local function identity(raw)
 bytes=bytes+#raw
 if #raw>262144 or bytes>4194304 then error('maintenance payload bound exceeded') end
 local ok,p=pcall(cjson.decode,raw)
 if not ok or type(p)~='table' or type(p.uuid)~='string' or #p.uuid==0 or #p.uuid>100 or type(p._current_dispatch_id)~='string' or not string.match(p._current_dispatch_id,'^[0-7][0-9A-HJKMNP-TV-Z]+$') or #p._current_dispatch_id~=26 then error('unsupported native payload') end
 return {uuid=p.uuid,dispatch=p._current_dispatch_id}
end
local active={}
for _,raw in ipairs(redis.call('ZRANGE',KEYS[3],0,-1)) do local p=identity(raw);active[p.uuid..':'..p.dispatch]=true end
local ids={};local seen={}
local function collect(values)
 for _,raw in ipairs(values) do local p=identity(raw);local k=p.uuid..':'..p.dispatch
  if not active[k] and not seen[k] then ids[#ids+1]=p;seen[k]=true end
 end
end
collect(redis.call('LRANGE',KEYS[1],0,-1));collect(redis.call('ZRANGE',KEYS[2],0,-1))
local now=redis.call('TIME')
local record=cjson.encode({version=1,operation=ARGV[1],connection=ARGV[2],queue=ARGV[3],observed_at=tonumber(now[1])+tonumber(now[2])/1000000,removed=n,identities=ids})
if #record>262144 then return redis.error_reply('maintenance receipt bound exceeded') end
redis.call('SET',KEYS[5],record,'EX',86400)
redis.call('DEL',KEYS[1],KEYS[2],KEYS[4])
return record
LUA, 5, $ready, $ready.':delayed', $ready.':reserved', $ready.':notify', $key, $operation, $connection, $queue, $resume ? '1' : '0');
        if (! is_string($raw) || strlen($raw) > 262144) { throw new RuntimeException('Clear outcome unconfirmed; retain the operation ID.'); }
        $record = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (($record['version'] ?? null) !== 1 || ($record['operation'] ?? null) !== $operation || ($record['connection'] ?? null) !== $connection || ($record['queue'] ?? null) !== $queue
            || ! is_int($record['removed'] ?? null) || $record['removed'] < 0 || $record['removed'] > 1000
            || ! is_numeric($record['observed_at'] ?? null) || ! is_finite((float) $record['observed_at'])
            || $record['observed_at'] <= 0 || $record['observed_at'] > microtime(true) + 300 || $record['observed_at'] < microtime(true) - 86700
            || ! is_array($record['identities'] ?? null) || count($record['identities']) > 1000) { throw new RuntimeException('Invalid maintenance receipt.'); }
        foreach ($record['identities'] as $identity) {
            if (! is_array($identity) || ! is_string($identity['uuid'] ?? null) || $identity['uuid'] === '' || strlen($identity['uuid']) > 100
                || ! DispatchIdentity::fromPayload(['_current_dispatch_id' => $identity['dispatch'] ?? null])) { throw new RuntimeException('Invalid clear identity.'); }
        }
        app(Maintenance::class)->cleared($connection, $record);
        return $record;
    }
}
