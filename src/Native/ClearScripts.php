<?php

namespace Current\Native;

/** Redis holds the detached snapshot and one replayable SQL projection receipt. */
final class ClearScripts
{
    public static function step(): string
    {
        return <<<'LUA'
local raw=redis.call('GET',KEYS[5])
local m
local function refresh()
 for i=5,10 do if redis.call('EXISTS',KEYS[i])==1 then redis.call('EXPIRE',KEYS[i],86400) end end
end
local function save() redis.call('SET',KEYS[5],cjson.encode(m),'EX',86400);refresh() end
if not raw then
 if ARGV[4]=='1' then return redis.error_reply('operation receipt unavailable') end
 for i=6,10 do if redis.call('EXISTS',KEYS[i])==1 then return redis.error_reply('operation staging unavailable') end end
 local now=redis.call('TIME')
 local waiting=redis.call('LLEN',KEYS[1])+redis.call('ZCARD',KEYS[2])
 local active=redis.call('ZCARD',KEYS[3])
 local reserved=ARGV[5]=='1' and active or 0
 m={version=2,operation=ARGV[1],connection=ARGV[2],queue=ARGV[3],including_reserved=ARGV[5]=='1',index_owner=ARGV[6],reservations_verified=ARGV[5]=='1',observed_at=tonumber(now[1])+tonumber(now[2])/1000000,removed=waiting+reserved,removed_waiting=waiting,removed_reserved=reserved,reserved_size=active,processed=0,untracked=0,sequence=0,cursor='0',phase='reservations',complete=false}
 if redis.call('EXISTS',KEYS[1])==1 then redis.call('RENAME',KEYS[1],KEYS[6]) end
 if redis.call('EXISTS',KEYS[2])==1 then redis.call('RENAME',KEYS[2],KEYS[7]) end
 if m.including_reserved and redis.call('EXISTS',KEYS[3])==1 then redis.call('RENAME',KEYS[3],KEYS[8]) end
 redis.call('DEL',KEYS[4])
 if m.removed==0 then m.phase='done';m.complete=true end
 save()
else
 m=cjson.decode(raw)
 if m.version~=2 or m.operation~=ARGV[1] or m.connection~=ARGV[2] or m.queue~=ARGV[3] or m.including_reserved~=(ARGV[5]=='1') then return redis.error_reply('operation scope mismatch') end
end
if m.complete then return cjson.encode({summary=m}) end
local old=redis.call('GET',KEYS[10]);if old then refresh();return old end
local function utf8(value)
 local i=1
 while i<=#value do
  local b=string.byte(value,i);local n=0;local low=128;local high=191
  if b<128 then n=0
  elseif b>=194 and b<=223 then n=1
  elseif b>=224 and b<=239 then n=2;if b==224 then low=160 elseif b==237 then high=159 end
  elseif b>=240 and b<=244 then n=3;if b==240 then low=144 elseif b==244 then high=143 end
  else return false end
  if i+n>#value then return false end
  if n>0 then local c=string.byte(value,i+1);if c<low or c>high then return false end end
  for j=2,n do local c=string.byte(value,i+j);if c<128 or c>191 then return false end end
  i=i+n+1
 end
 return true
end
local function identity(value)
 if #value>262144 then return nil end
 local ok,p=pcall(cjson.decode,value)
 if not ok or type(p)~='table' or type(p.uuid)~='string' or #p.uuid==0 or #p.uuid>100 or not utf8(p.uuid) then return nil end
 local d=p._current_dispatch_id
 if d==nil or d==cjson.null then d='' end
 if type(d)~='string' or (d~='' and (#d~=26 or not string.match(d,'^[0-7][0-9A-HJKMNP-TV-Z]+$'))) then return nil end
 return {uuid=p.uuid,dispatch=d}
end
if m.phase=='reservations' then
 local source=m.including_reserved and KEYS[8] or KEYS[3]
 local size=redis.call('ZCARD',source)
 if m.reserved_size==nil then m.reserved_size=size;m.cursor='0';m.unknown_reserved=true end
 if size~=m.reserved_size then m.unknown_reserved=true end
 local index=tonumber(m.cursor)
 local values=redis.call('ZRANGE',source,index,index+99)
 for _,value in ipairs(values) do
  local p=identity(value);if p then redis.call('SADD',KEYS[9],p.uuid..':'..p.dispatch) else m.unknown_reserved=true end
 end
 m.cursor=tostring(index+#values);if #values<100 or index+#values>=m.reserved_size then m.phase='waiting' end
 save();return cjson.encode({summary=m})
end
if not m.reservations_verified then refresh();return cjson.encode({summary=m}) end
local ids={};local seen={};local n=0;local bytes=0;local unknown=0
while n<100 and bytes<4194304 do
 local value=nil;local reserved=false
 if m.phase=='waiting' then
  value=redis.call('LINDEX',KEYS[6],0)
  if value then redis.call('LPOP',KEYS[6])
  else
   local values=redis.call('ZRANGE',KEYS[7],0,0);value=values[1]
   if value then redis.call('ZREM',KEYS[7],value) else m.phase='reserved' end
  end
 end
 if m.phase=='reserved' and not value then
  local values=redis.call('ZRANGE',KEYS[8],0,0);value=values[1];reserved=true
  if value then redis.call('ZREM',KEYS[8],value) else m.phase='done';break end
 end
 if value then
  n=n+1;bytes=bytes+#value
  local p=identity(value)
  if not p then unknown=unknown+1
  elseif not reserved and not m.unknown_reserved then
   local k=p.uuid..':'..p.dispatch
   if redis.call('SISMEMBER',KEYS[9],k)==0 and not seen[k] then ids[#ids+1]=p;seen[k]=true end
  end
 end
end
m.sequence=m.sequence+1;m.processed=m.processed+n;m.untracked=m.untracked+unknown
local receipt={summary=m,identities=ids,sequence=m.sequence}
local encoded=cjson.encode(receipt)
redis.call('SET',KEYS[10],encoded,'EX',86400);save();return encoded
LUA;
    }

    public static function ack(): string
    {
        return <<<'LUA'
local raw=redis.call('GET',KEYS[1]);if not raw then return redis.error_reply('operation receipt unavailable') end
local m=cjson.decode(raw);local pending=redis.call('GET',KEYS[2])
if pending then
 local receipt=cjson.decode(pending)
 if tostring(receipt.sequence)==ARGV[1] then redis.call('DEL',KEYS[2])
 else return cjson.encode(m) end
end
if m.phase=='done' then m.complete=true;redis.call('DEL',KEYS[3]) end
redis.call('SET',KEYS[1],cjson.encode(m),'EX',86400)
return cjson.encode(m)
LUA;
    }

    public static function verifyReservations(): string
    {
        return <<<'LUA'
local raw=redis.call('GET',KEYS[1]);if not raw then return redis.error_reply('operation receipt unavailable') end
local m=cjson.decode(raw)
if not m.reservations_verified then
 if ARGV[1]~='1' then m.unknown_reserved=true end
 m.reservations_verified=true
 redis.call('SET',KEYS[1],cjson.encode(m),'EX',86400)
end
return cjson.encode(m)
LUA;
    }
}
