local key = KEYS[1]

-- With ARGV[1] == "access", also read the LFU counter and the idle time. They are read first because
-- the item counts below (SCARD, HLEN, ...) count as an access and would bump the counter and reset the idle time.
local access = ARGV[1] == "access"
local freq = -1
local idle = -1

if access then
    -- Each one only works under its own kind of maxmemory-policy (LFU or LRU), the other one errors.
    local ok_freq, value_freq = pcall(function()
        return redis.call("OBJECT", "FREQ", key)
    end)
    freq = ok_freq and value_freq or -1

    local ok_idle, value_idle = pcall(function()
        return redis.call("OBJECT", "IDLETIME", key)
    end)
    idle = ok_idle and value_idle or -1
end

local ttl = redis.call("TTL", key)
local key_type = redis.call("TYPE", key)["ok"]

local ok, mem = pcall(function()
    return redis.call("MEMORY", "USAGE", key)
end)
local memory_usage = ok and mem or 0

local count

if key_type == "set" then
    count = redis.call("SCARD", key)
elseif key_type == "list" then
    count = redis.call("LLEN", key)
elseif key_type == "zset" then
    count = redis.call("ZCARD", key)
elseif key_type == "hash" then
    count = redis.call("HLEN", key)
elseif key_type == "stream" then
    count = redis.call("XLEN", key)
elseif key_type == "vectorset" then
    count = redis.call("VCARD", key)
end

if access then
    -- A nil would end the reply there, so a missing count goes as false (a null reply) to keep freq and idle.
    return { ttl, key_type, memory_usage, count or false, freq, idle }
end

return { ttl, key_type, memory_usage, count }
