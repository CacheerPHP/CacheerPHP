# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:20:18Z |
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
| keys | Key::named() | 150 |  |
| keys | Key::within(scope) | 240 |  |
| keys | Key::identity() | 119 |  |
| keys | HashingKeyEncoder::encode() | 663 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 182 |  |
| call path | Cacheer::get(string) | 702 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,522 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 864 |  |
| call path | Cacheer::remember() hit | 750 |  |
| call path | Cacheer::set(string) | 932 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 316 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 511 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 533 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 2,116 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 4,615 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,226 | JSON header + value copy |
| redis | Predis GET — network floor | 85,016 |  |
| redis | RedisStore::get(Key) | 86,129 |  |
| redis | Cacheer(redis)::get(string) | 87,171 |  |
| redis | cold remember(): Redis commands | 7 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 1,036 |  |
| file | Cacheer::file()->get() 1 KiB | 20,093 |  |
| monitoring | plain get() hit 500-row array | 717 |  |
| monitoring | instrumented get() hit 1 KiB | 1,420 |  |
| monitoring | instrumented get() hit 64 KiB | 1,418 |  |
| monitoring | instrumented get() hit 500-row array | 1,391 |  |
