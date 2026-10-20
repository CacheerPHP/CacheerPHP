# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:23:43Z |
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
| keys | Key::named() | 152 |  |
| keys | Key::within(scope) | 242 |  |
| keys | Key::identity() | 120 |  |
| keys | HashingKeyEncoder::encode() | 686 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 182 |  |
| call path | Cacheer::get(string) | 723 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,586 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 901 |  |
| call path | Cacheer::remember() hit | 790 |  |
| call path | Cacheer::set(string) | 986 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 330 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 540 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 578 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 2,315 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 4,837 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,294 | JSON header + value copy |
| redis | Predis GET — network floor | 88,060 |  |
| redis | RedisStore::get(Key) | 95,973 |  |
| redis | Cacheer(redis)::get(string) | 96,035 |  |
| redis | cold remember(): Redis commands | 7 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 1,090 |  |
| memory | tiered worker: KiB retained after 50k writes expired | 2,119 | KiB, not ns |
| file | Cacheer::file()->get() 1 KiB | 25,815 |  |
| monitoring | plain get() hit 500-row array | 761 |  |
| monitoring | instrumented get() hit 1 KiB | 1,442 |  |
| monitoring | instrumented get() hit 64 KiB | 1,529 |  |
| monitoring | instrumented get() hit 500-row array | 1,429 |  |
