# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:16:34Z |
| os | Darwin 27.0.0 (arm64) |
| cpu | Apple M4, 10 cores |
| memory | 16 GB |
| php | 8.5.11 (cli) |
| opcache/jit | opcache off, jit disable |
| cacheer-php | 6.x-dev @ 966e5ae |
| predis | v2.4.1 |
| redis | 8.2.0 on 127.0.0.1:6391 (loopback) |

Median ns per operation over 7 batches of 20000 (Redis/File: fewer).

| Group | Measurement | ns/op | Note |
|---|---|---|---|
| keys | Key::named() | 147 |  |
| keys | Key::within(scope) | 234 |  |
| keys | Key::identity() | 114 |  |
| keys | HashingKeyEncoder::encode() | 647 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 175 |  |
| call path | Cacheer::get(string) | 677 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,504 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 826 |  |
| call path | Cacheer::remember() hit | 713 |  |
| call path | Cacheer::set(string) | 911 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 300 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 492 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 520 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 1,996 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 4,710 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,258 | JSON header + value copy |
| redis | Predis GET — network floor | 85,969 |  |
| redis | RedisStore::get(Key) | 87,320 |  |
| redis | Cacheer(redis)::get(string) | 88,253 |  |
| redis | cold remember(): Redis commands | 7 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 1,022 |  |
| file | Cacheer::file()->get() 1 KiB | 19,841 |  |
| monitoring | instrumented get() hit 1 KiB | 1,415 | serialize() of every value to size it |
| monitoring | instrumented get() hit 64 KiB | 2,135 | serialize() of every value to size it |
