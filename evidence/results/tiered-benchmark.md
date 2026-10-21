# Tiered caching benchmark

## Environment

| | |
|---|---|
| date | 2026-10-20T23:42:38Z |
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
- write ratio: 0.05
- key distribution: Zipf, s = 1.0
- seed: 42

## Results (median of runs)

| Backend | Shape | ops/s (min–max) | p50 µs | p95 µs | p99 µs | L2 calls | Redis commands | vs. alone |
|---|---|---|---|---|---|---|---|---|
| Redis | alone | 11,315 (11,102–11,349) | 87.5 | 95.5 | 102.7 | 50,000 | 50,000 | 1.00× |
| Redis | tiered (Array L1) | 139,646 (138,811–140,939) | 0.9 | 87.8 | 94.0 | 3,436 | 3,436 | 12.34× |
| SQLite file | alone | 45,503 (28,806–48,033) | 8.7 | 117.5 | 296.0 | 50,000 | — | 1.00× |
| SQLite file | tiered (Array L1) | 70,428 (66,814–73,971) | 1.0 | 30.2 | 276.1 | 3,436 | — | 1.55× |
