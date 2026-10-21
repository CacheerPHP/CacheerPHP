# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:37:42Z |
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
| keys | Key::named() | 176 |  |
| keys | Key::within(scope) | 186 |  |
| keys | Key::identity() | 21 |  |
| keys | HashingKeyEncoder::encode() | 508 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 77 |  |
| call path | Cacheer::get(string) | 495 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,231 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 646 |  |
| call path | Cacheer::remember() hit | 530 |  |
| call path | Cacheer::set(string) | 698 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 308 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 518 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 529 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 2,284 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 2,747 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,249 | JSON header + value copy |
| redis | Predis GET — network floor | 86,008 |  |
| redis | RedisStore::get(Key) | 87,404 |  |
| redis | Cacheer(redis)::get(string) | 87,800 |  |
| redis | setMany(100) | 861,380 |  |
| redis | many(100) | 437,640 |  |
| redis | setMany(100) + deleteMany(100) | 1,224,965 |  |
| redis | cold remember(): Redis commands | 6 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 844 |  |
| memory | tiered worker: KiB retained after 50k writes expired | 1,091 | KiB, not ns |
| file | Cacheer::file()->get() 1 KiB | 20,015 |  |
| monitoring | plain get() hit 500-row array | 508 |  |
| monitoring | instrumented get() hit 1 KiB | 1,198 |  |
| monitoring | instrumented get() hit 64 KiB | 1,198 |  |
| monitoring | instrumented get() hit 500-row array | 1,172 |  |
