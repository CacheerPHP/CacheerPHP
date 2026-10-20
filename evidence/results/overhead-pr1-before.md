# Library overhead

| | |
|---|---|
| date | 2026-10-20T23:20:40Z |
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
| keys | Key::within(scope) | 233 |  |
| keys | Key::identity() | 117 |  |
| keys | HashingKeyEncoder::encode() | 661 | sha256 + readable slug, every backend call |
| call path | ArrayStore::get(Key) — raw store | 179 |  |
| call path | Cacheer::get(string) | 697 | Key::named + run() wrapper |
| call path | Cacheer::in(tenant)->get(string) | 1,525 | builds a scoped view per call |
| call path | scoped view reused ->get(string) | 859 |  |
| call path | Cacheer::remember() hit | 749 |  |
| call path | Cacheer::set(string) | 923 |  |
| pipeline | EnvelopeCodec::encode() 1 KiB | 310 |  |
| pipeline | EnvelopeCodec::decode() 1 KiB | 502 |  |
| pipeline | StoredRecord::fromString() 1 KiB | 531 | JSON header + value copy |
| pipeline | EnvelopeCodec::encode() 64 KiB | 2,053 |  |
| pipeline | EnvelopeCodec::decode() 64 KiB | 4,712 |  |
| pipeline | StoredRecord::fromString() 64 KiB | 1,225 | JSON header + value copy |
| redis | Predis GET — network floor | 86,323 |  |
| redis | RedisStore::get(Key) | 87,448 |  |
| redis | Cacheer(redis)::get(string) | 88,258 |  |
| redis | cold remember(): Redis commands | 7 | count, not ns — GET, lock SET NX, GET, SET, lock release |
| tiered | TieredStore L1 hit via Cacheer::get | 1,034 |  |
| file | Cacheer::file()->get() 1 KiB | 20,182 |  |
| monitoring | plain get() hit 500-row array | 701 |  |
| monitoring | instrumented get() hit 1 KiB | 1,413 |  |
| monitoring | instrumented get() hit 64 KiB | 2,227 |  |
| monitoring | instrumented get() hit 500-row array | 36,909 |  |
