# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:33:10Z |
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
| keys | Key::named() | 174 |  |
| keys | Key::within(scope) | 190 |  |
| keys | Key::identity() | 22 |  |
| keys | HashingKeyEncoder::encode() | 521 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 77 |  |
| call path | Cacheer::get(string) | 494 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,241 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 660 |  |
| call path | Cacheer::remember() hit | 534 |  |
| call path | Cacheer::set(string) | 718 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 316 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 523 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 553 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 2,187 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 2,720 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,254 | JSON header + value copy |
| redis | Predis GET — network floor | 84,631 |  |
| redis | RedisStore::get(Key) | 86,860 |  |
| redis | Cacheer(redis)::get(string) | 91,469 |  |
| redis | setMany(100) | 900,139 |  |
| redis | many(100) | 455,681 |  |
| redis | setMany(100) + deleteMany(100) | 1,250,641 |  |
| redis | cold remember(): Redis commands | 7 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 875 |  |
| memory | tiered worker: KiB retained after 50k writes expired | 1,091 | KiB, not ns |
| file | Cacheer::file()->get() 1 KiB | 21,979 |  |
| monitoring | plain get() hit 500-row array | 528 |  |
| monitoring | instrumented get() hit 1 KiB | 1,201 |  |
| monitoring | instrumented get() hit 64 KiB | 1,200 |  |
| monitoring | instrumented get() hit 500-row array | 1,175 |  |
