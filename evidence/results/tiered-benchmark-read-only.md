# Tiered caching benchmark

## Environment

| | |
|---|---|
| date | 2026-10-20T23:43:16Z |
| os | Darwin 27.0.0 (arm64) |
| cpu | Apple M4, 10 cores |
| memory | 16 GB |
| php | 8.5.11 (cli) |
| opcache/jit | opcache off, jit disable |
| cacheer-php | perf/pr-7-rebaseline @ 4141762 (this checkout) |
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
| Redis | alone | 11,262 (11,044–11,344) | 87.3 | 95.9 | 104.8 | 50,000 | 50,000 | 1.00× |
| Redis | tiered (Array L1) | 359,287 (356,790–361,452) | 0.9 | 1.2 | 91.5 | 1,001 | 1,001 | 31.90× |
| SQLite file | alone | 117,816 (113,681–118,745) | 8.3 | 9.1 | 10.5 | 50,000 | — | 1.00× |
| SQLite file | tiered (Array L1) | 899,244 (888,988–909,510) | 0.8 | 1.1 | 10.8 | 1,001 | — | 7.63× |
