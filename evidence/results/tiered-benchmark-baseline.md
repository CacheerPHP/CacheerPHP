# Tiered caching benchmark

## Environment

| | |
|---|---|
| date | 2026-10-20T22:51:11Z |
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
- write ratio: 0.05
- key distribution: Zipf, s = 1.0
- seed: 42

## Results (median of runs)

| Backend | Shape | ops/s (min–max) | p50 µs | p95 µs | p99 µs | L2 calls | Redis commands | vs. alone |
|---|---|---|---|---|---|---|---|---|
| Redis | alone | 10,594 (9,952–11,250) | 89.0 | 111.9 | 228.1 | 50,000 | 50,000 | 1.00× |
| Redis | tiered (Array L1) | 126,085 (116,549–133,757) | 1.2 | 89.4 | 98.1 | 3,436 | 3,436 | 11.90× |
| SQLite file | alone | 16,624 (14,622–28,481) | 9.0 | 56.8 | 381.4 | 50,000 | — | 1.00× |
| SQLite file | tiered (Array L1) | 21,064 (20,523–52,329) | 1.1 | 35.3 | 361.9 | 3,436 | — | 1.27× |
