# Tiered caching benchmark

## Environment

| | |
|---|---|
| date | 2026-10-20T23:34:00Z |
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
| Redis | alone | 11,033 (9,589–11,333) | 87.0 | 99.2 | 112.0 | 50,000 | 50,000 | 1.00× |
| Redis | tiered (Array L1) | 364,110 (362,890–365,764) | 0.9 | 1.2 | 90.4 | 1,001 | 1,001 | 33.00× |
| SQLite file | alone | 118,314 (117,942–120,341) | 8.3 | 8.9 | 10.5 | 50,000 | — | 1.00× |
| SQLite file | tiered (Array L1) | 895,042 (881,500–895,879) | 0.9 | 1.1 | 10.6 | 1,001 | — | 7.56× |
