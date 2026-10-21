# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:42:17Z |
| os | Darwin 27.0.0 (arm64) |
| cpu | Apple M4, 10 cores |
| memory | 16 GB |
| php | 8.5.11 (cli) |
| opcache/jit | opcache off, jit disable |
| cacheer-php | perf/pr-7-rebaseline @ 4141762 (this checkout) |
| predis | v2.4.1 |
| redis | 8.2.0 on 127.0.0.1:6391 (loopback) |

Median ns per operation over 7 batches of 20000 (Redis/File: fewer).

| Group | Measurement | ns/op | Note |
|---|---|---|---|
| keys | Key::named() | 168 |  |
| keys | Key::within(scope) | 193 |  |
| keys | Key::identity() | 22 |  |
| keys | HashingKeyEncoder::encode() | 511 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 78 |  |
| call path | Cacheer::get(string) | 510 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,249 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 666 |  |
| call path | Cacheer::remember() hit | 545 |  |
| call path | Cacheer::set(string) | 709 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 312 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 532 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 588 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 2,087 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 2,685 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,192 | JSON header + value copy |
| redis | Predis GET — network floor | 85,236 |  |
| redis | RedisStore::get(Key) | 88,546 |  |
| redis | Cacheer(redis)::get(string) | 87,974 |  |
| redis | setMany(100) | 911,545 |  |
| redis | many(100) | 449,952 |  |
| redis | setMany(100) + deleteMany(100) | 1,279,675 |  |
| redis | cold remember(): Redis commands | 6 | count, not ns; the lock-release script counts as 2 |
| tiered | TieredStore L1 hit via Cacheer::get | 853 |  |
| memory | tiered worker: KiB retained after 50k writes expired | 1,091 | KiB, not ns |
| file | Cacheer::file()->get() 1 KiB | 21,804 |  |
| monitoring | plain get() hit 500-row array | 523 |  |
| monitoring | instrumented get() hit 1 KiB | 1,226 |  |
| monitoring | instrumented get() hit 64 KiB | 1,157 |  |
| monitoring | instrumented get() hit 500-row array | 1,147 |  |
