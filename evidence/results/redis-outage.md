# Redis outage demonstration

| | |
|---|---|
| date | 2026-10-20T23:43:46Z |
| os | Darwin 27.0.0 (arm64) |
| cpu | Apple M4, 10 cores |
| memory | 16 GB |
| php | 8.5.11 (cli) |
| opcache/jit | opcache off, jit disable |
| cacheer-php | perf/pr-7-rebaseline @ 4141762 (this checkout) |
| predis | v2.4.1 |
| redis | 8.2.0 on 127.0.0.1:6391 (loopback) |

ResilientStore: Redis primary (connect/read timeout 0.25s) → FileStore fallback; breaker opens after 3 failures and probes again after 2.0s.

| Phase | t (s) | Request | Result | Latency (ms) | Breaker after |
|---|---|---|---|---|---|
| 1 healthy | 0.07 | `set('user:1', 'Ada')` | ok | 1.6 | closed |
| 1 healthy | 0.07 | `set('session:9', 'active')` | ok | 0.3 | closed |
| 1 healthy | 0.07 | `increment('logins')` | 1 | 1.6 | closed |
| 1 healthy | 0.08 | `remember('report', …)` | 'built while healthy' | 1.0 | closed |
| 1 healthy | 0.08 | `lock('nightly')->acquire()` | true | 0.3 | closed |
| 2 frozen | 0.33 | `get('user:1')` | 'Ada' | 252.6 | closed |
| 2 frozen | 0.58 | `get('user:1')` | 'Ada' | 251.6 | closed |
| 2 frozen | 0.83 | `get('user:1')` | 'Ada' | 251.6 | open |
| 2 frozen | 0.83 | `get('user:1')` | 'Ada' | 0.1 | open |
| 2 frozen | 0.83 | `get('user:1')` | 'Ada' | 0.0 | open |
| 2 frozen | 0.83 | `delete('session:9')` | true | 0.2 | open |
| 2 frozen | 0.83 | `set('user:1', 'Ada Lovelace')` | ok | 0.5 | open |
| 2 frozen | 0.83 | `get('user:1')` | 'Ada Lovelace' | 0.1 | open |
| 2 frozen | 0.83 | `increment('logins')` | refused: Cache store operation "increment" failed for key "logins". | 0.7 | open |
| 2 frozen | 0.83 | `lock('nightly')->acquire()` | false | 0.0 | open |
| 2 frozen | 0.83 | `remember('dashboard', …)` | 'built during the outage' | 0.7 | open |
| 3 recovered | 2.94 | `get('session:9')` | 'miss' | 1.7 | closed |
| 3 recovered | 2.94 | `get('user:1')` | 'miss' | 0.2 | closed |
| 3 recovered | 2.94 | `increment('logins')` | 2 | 1.0 | closed |
| 3 recovered | 2.94 | `get('report')` | 'built while healthy' | 0.2 | closed |
| 4 killed | 2.94 | `get('report')` | 'built while healthy' | 0.2 | closed |
| 4 killed | 2.94 | `get('report')` | 'built while healthy' | 0.2 | closed |
| 4 killed | 2.94 | `get('report')` | 'built while healthy' | 0.2 | open |
| 4 killed | 2.94 | `get('report')` | 'built while healthy' | 0.1 | open |
