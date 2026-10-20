# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:27:59Z |
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
| keys | Key::named() | 145 |  |
| keys | Key::within(scope) | 249 |  |
| keys | Key::identity() | 162 |  |
| keys | HashingKeyEncoder::encode() | 792 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 245 |  |
| call path | Cacheer::get(string) | 739 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,531 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 866 |  |
| call path | Cacheer::remember() hit | 724 |  |
| call path | Cacheer::set(string) | 957 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 323 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 525 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 557 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 2,025 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 4,826 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,254 | JSON header + value copy |
| redis | Predis GET — network floor | 83,120 |  |
| redis | RedisStore::get(Key) | 87,030 |  |
| redis | Cacheer(redis)::get(string) | 88,759 |  |
| redis | setMany(100) | 8,948,815 |  |
| redis | many(100) | 434,126 |  |
| redis | setMany(100) + deleteMany(100) | 18,400,971 |  |
| redis | cold remember(): Redis commands | 7 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 2,437 |  |
| memory | tiered worker: KiB retained after 50k writes expired | 2,119 | KiB, not ns |
| file | Cacheer::file()->get() 1 KiB | 19,828 |  |
| monitoring | plain get() hit 500-row array | 705 |  |
| monitoring | instrumented get() hit 1 KiB | 1,399 |  |
| monitoring | instrumented get() hit 64 KiB | 1,375 |  |
| monitoring | instrumented get() hit 500-row array | 1,354 |  |
