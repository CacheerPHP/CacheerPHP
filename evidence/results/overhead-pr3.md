# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:27:39Z |
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
| keys | Key::named() | 148 |  |
| keys | Key::within(scope) | 240 |  |
| keys | Key::identity() | 115 |  |
| keys | HashingKeyEncoder::encode() | 665 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 181 |  |
| call path | Cacheer::get(string) | 712 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,535 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 839 |  |
| call path | Cacheer::remember() hit | 757 |  |
| call path | Cacheer::set(string) | 949 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 304 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 498 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 527 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 1,893 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 4,662 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,105 | JSON header + value copy |
| redis | Predis GET — network floor | 85,719 |  |
| redis | RedisStore::get(Key) | 86,825 |  |
| redis | Cacheer(redis)::get(string) | 87,032 |  |
| redis | setMany(100) | 878,254 |  |
| redis | many(100) | 459,829 |  |
| redis | setMany(100) + deleteMany(100) | 1,263,856 |  |
| redis | cold remember(): Redis commands | 7 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 1,070 |  |
| memory | tiered worker: KiB retained after 50k writes expired | 2,119 | KiB, not ns |
| file | Cacheer::file()->get() 1 KiB | 21,314 |  |
| monitoring | plain get() hit 500-row array | 738 |  |
| monitoring | instrumented get() hit 1 KiB | 1,453 |  |
| monitoring | instrumented get() hit 64 KiB | 1,437 |  |
| monitoring | instrumented get() hit 500-row array | 1,359 |  |
