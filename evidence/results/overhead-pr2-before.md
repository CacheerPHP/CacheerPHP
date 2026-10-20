# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:23:08Z |
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
| keys | Key::named() | 154 |  |
| keys | Key::within(scope) | 266 |  |
| keys | Key::identity() | 117 |  |
| keys | HashingKeyEncoder::encode() | 715 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 425 |  |
| call path | Cacheer::get(string) | 715 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,568 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 868 |  |
| call path | Cacheer::remember() hit | 771 |  |
| call path | Cacheer::set(string) | 954 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 320 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 530 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 556 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 2,179 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 4,781 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,339 | JSON header + value copy |
| redis | Predis GET — network floor | 83,827 |  |
| redis | RedisStore::get(Key) | 92,579 |  |
| redis | Cacheer(redis)::get(string) | 94,364 |  |
| redis | cold remember(): Redis commands | 7 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 1,025 |  |
| memory | tiered worker: KiB retained after 50k writes expired | 39,819 | KiB, not ns |
| file | Cacheer::file()->get() 1 KiB | 22,387 |  |
| monitoring | plain get() hit 500-row array | 721 |  |
| monitoring | instrumented get() hit 1 KiB | 1,422 |  |
| monitoring | instrumented get() hit 64 KiB | 1,420 |  |
| monitoring | instrumented get() hit 500-row array | 1,389 |  |
