# Tiered caching benchmark

## Environment

| | |
|---|---|
| date | 2026-10-20T22:52:24Z |
| os | Darwin 27.0.0 (arm64) |
| cpu | Apple M4, 10 cores |
| memory | 16 GB |
| php | 8.5.11 (cli) |
| opcache/jit | opcache off, jit disable |
| cacheer-php | 6.x-dev @ 966e5ae |
| predis | v2.4.1 |
| redis | 8.2.0 on 127.0.0.1:6391 (loopback) |

## Workload

- operations: 50000
- runs per configuration: 5
- keys: 1000
- value size: 1024 bytes
- write ratio: 0
- key distribution: Zipf, s = 1.0
- seed: 42

## Results (median of runs)

| Backend | Shape | ops/s (min–max) | p50 µs | p95 µs | p99 µs | L2 calls | Redis commands | vs. alone |
|---|---|---|---|---|---|---|---|---|
| Redis | alone | 11,171 (10,520–11,221) | 88.0 | 98.4 | 126.9 | 50,000 | 50,000 | 1.00× |
| Redis | tiered (Array L1) | 336,997 (333,952–339,979) | 1.0 | 1.5 | 91.5 | 1,001 | 1,001 | 30.17× |
| SQLite file | alone | 113,560 (112,813–113,794) | 8.6 | 9.4 | 11.0 | 50,000 | — | 1.00× |
| SQLite file | tiered (Array L1) | 763,910 (752,436–769,373) | 1.0 | 1.3 | 11.1 | 1,001 | — | 6.73× |
