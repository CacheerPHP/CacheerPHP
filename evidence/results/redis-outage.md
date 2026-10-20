# Redis outage demonstration

| | |
|---|---|
| date | 2026-10-20T22:53:33Z |
| os | Darwin 27.0.0 (arm64) |
| cpu | Apple M4, 10 cores |
| memory | 16 GB |
| php | 8.5.11 (cli) |
| opcache/jit | opcache off, jit disable |
| cacheer-php | 6.x-dev @ 966e5ae |
| predis | v2.4.1 |
| redis | 8.2.0 on 127.0.0.1:6391 (loopback) |

ResilientStore: Redis primary (connect/read timeout 0.25s) → FileStore fallback; breaker opens after 3 failures and probes again after 2.0s.

| Phase | t (s) | Request | Result | Latency (ms) | Breaker after |
|---|---|---|---|---|---|
| 1 healthy | 0.03 | `set('user:1', 'Ada')` | ok | 2.1 | closed |
| 1 healthy | 0.03 | `set('session:9', 'active')` | ok | 0.4 | closed |
| 1 healthy | 0.03 | `increment('logins')` | 1 | 2.5 | closed |
| 1 healthy | 0.03 | `remember('report', …)` | 'built while healthy' | 1.1 | closed |
| 1 healthy | 0.03 | `lock('nightly')->acquire()` | true | 0.3 | closed |
| 2 frozen | 0.28 | `get('user:1')` | 'Ada' | 252.2 | closed |
| 2 frozen | 0.54 | `get('user:1')` | 'Ada' | 251.5 | closed |
| 2 frozen | 0.79 | `get('user:1')` | 'Ada' | 251.4 | open |
| 2 frozen | 0.79 | `get('user:1')` | 'Ada' | 0.1 | open |
| 2 frozen | 0.79 | `get('user:1')` | 'Ada' | 0.1 | open |
| 2 frozen | 0.79 | `delete('session:9')` | true | 0.2 | open |
| 2 frozen | 0.79 | `set('user:1', 'Ada Lovelace')` | ok | 0.5 | open |
| 2 frozen | 0.79 | `get('user:1')` | 'Ada Lovelace' | 0.1 | open |
| 2 frozen | 0.79 | `increment('logins')` | refused: Cache store operation "increment" failed for key "logins". | 0.7 | open |
| 2 frozen | 0.79 | `lock('nightly')->acquire()` | false | 0.0 | open |
| 2 frozen | 0.79 | `remember('dashboard', …)` | 'built during the outage' | 1.1 | open |
| 3 recovered | 2.90 | `get('session:9')` | 'miss' | 2.5 | closed |
| 3 recovered | 2.90 | `get('user:1')` | 'miss' | 0.2 | closed |
| 3 recovered | 2.90 | `increment('logins')` | 2 | 0.9 | closed |
| 3 recovered | 2.90 | `get('report')` | 'built while healthy' | 0.2 | closed |
| 4 killed | 2.90 | `get('report')` | 'built while healthy' | 0.1 | closed |
| 4 killed | 2.90 | `get('report')` | 'built while healthy' | 0.1 | closed |
| 4 killed | 2.90 | `get('report')` | 'built while healthy' | 0.1 | open |
| 4 killed | 2.90 | `get('report')` | 'built while healthy' | 0.0 | open |
