# CacheerPHP

<p align="center">
  <a href="https://github.com/silviooosilva/CacheerPHP"><img src="./art/cacheer_php_logo__.png" width="450" alt="CacheerPHP Logo"/></a>
</p>

<p align="center">
  <strong>A modern, fluent PHP caching library with multiple backends, PSR compliance, encryption and zero framework dependencies.</strong>
</p>

<p align="center">
  <a href="https://github.com/silviooosilva/CacheerPHP/releases"><img src="https://img.shields.io/github/release/silviooosilva/CacheerPHP.svg?style=for-the-badge&color=blue" alt="Latest Version"/></a>
  <img src="https://img.shields.io/packagist/dependency-v/silviooosilva/cacheer-php/PHP?style=for-the-badge&color=blue" alt="PHP Version"/>
  <img src="https://img.shields.io/packagist/dt/silviooosilva/cacheer-php?style=for-the-badge&color=blue" alt="Downloads"/>
  <a href="https://scrutinizer-ci.com/g/silviooosilva/CacheerPHP"><img src="https://img.shields.io/scrutinizer/g/silviooosilva/CacheerPHP.svg?style=for-the-badge&color=blue" alt="Quality Score"/></a>
  <a href="https://github.com/silviooosilva/CacheerPHP"><img src="https://img.shields.io/badge/maintainer-@silviooosilva-blue.svg?style=for-the-badge&color=blue" alt="Maintainer"/></a>
</p>

---

## Why CacheerPHP?

Most PHP caching solutions are either too minimal or buried inside a framework. CacheerPHP gives you a **complete caching toolkit** that works anywhere — from a small script to a full application — with a clean, fluent API and no framework lock-in.

- **4 storage drivers** — File, Database (MySQL/PostgreSQL/SQLite), Redis and in-memory Array
- **PSR-16 & PSR-3** — Standards-compliant SimpleCache adapter and logger out of the box
- **AES-256-CBC encryption** — Protect sensitive cached data with a single method call
- **Gzip compression** — Reduce storage footprint automatically
- **Fluent OptionBuilder** — Type-safe, IDE-friendly configuration with zero typos
- **Tags & namespaces** — Group and invalidate related entries effortlessly
- **Human-readable TTL** — Write `"2 hours"` instead of `7200`
- **Static & instance API** — Use whichever style fits your codebase
- **150+ tests** — Battle-tested with PHPUnit

---

## Quick Start

```sh
composer require silviooosilva/cacheer-php
```

```php
use Silviooosilva\CacheerPhp\Cacheer;

$cache = new Cacheer(['cacheDir' => __DIR__ . '/cache']);
$cache->setDriver()->useFileDriver();

// Write
$cache->putCache('user:1', ['id' => 1, 'name' => 'John']);

// Read
$user = $cache->getCache('user:1');

// Check
if ($cache->has('user:1')) {
    echo "Cache hit!";
}
```

That's it — you're caching. Keep reading for the good stuff.

---

## Table of Contents

- [Drivers](#drivers)
- [Configuration](#configuration)
- [OptionBuilder](#optionbuilder)
- [Encryption & Compression](#encryption--compression)
- [Tags & Namespaces](#tags--namespaces)
- [PSR-16 SimpleCache](#psr-16-simplecache)
- [Formatter](#formatter)
- [API Reference](#api-reference)
- [Testing](#testing)
- [Documentation](#documentation)
- [Contributing](#contributing)
- [License](#license)

---

## Drivers

Switch between backends with a single call:

```php
$cache->setDriver()->useFileDriver();      // Filesystem
$cache->setDriver()->useDatabaseDriver();  // MySQL, PostgreSQL, SQLite
$cache->setDriver()->useRedisDriver();     // Redis
$cache->setDriver()->useArrayDriver();     // In-memory (great for tests)
```

| Feature | File | Database | Redis | Array |
|---|:---:|:---:|:---:|:---:|
| Persistence | Disk | DB | Server | No |
| Tags | Yes | Yes | Yes | Yes |
| Namespaces | Yes | Yes | Yes | Yes |
| Compression | Yes | Yes | Yes | Yes |
| Encryption | Yes | Yes | Yes | Yes |
| Auto-flush | Yes | Yes | Yes | - |
| Best for | Single server | Shared state | High throughput | Testing |

---

## Configuration

### Environment variables

Copy the example file and adjust:

```sh
cp .env.example .env
```

```env
# SQLite (default)
DB_CONNECTION=sqlite

# MySQL / PostgreSQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cacheer_db
DB_USERNAME=root
DB_PASSWORD=secret

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### Plain array

```php
$cache = new Cacheer([
    'cacheDir'       => __DIR__ . '/cache',
    'loggerPath'     => __DIR__ . '/logs/cacheer.log',
    'expirationTime' => '1 hour',
    'flushAfter'     => '1 day',
]);
```

### Static API

```php
Cacheer::setConfig()->setTimeZone('UTC');
Cacheer::setDriver()->useArrayDriver();

Cacheer::putCache('key', 'value');
$value = Cacheer::getCache('key');
```

---

## OptionBuilder

Forget string typos. The `OptionBuilder` gives you a **fluent, type-safe** way to configure each driver with full IDE autocompletion:

```php
use Silviooosilva\CacheerPhp\Config\Option\Builder\OptionBuilder;

$options = OptionBuilder::forFile()
    ->dir(__DIR__ . '/cache')
    ->loggerPath(__DIR__ . '/logs/cache.log')
    ->expirationTime('2 hours')
    ->flushAfter('1 day')
    ->build();

$cache = new Cacheer($options);
$cache->setDriver()->useFileDriver();
```

Each driver has its own builder with driver-specific methods:

```php
// Redis
$options = OptionBuilder::forRedis()
    ->setNamespace('app:')
    ->loggerPath(__DIR__ . '/logs/cache.log')
    ->expirationTime('2 hours')
    ->flushAfter('1 day')
    ->build();

// Database
$options = OptionBuilder::forDatabase()
    ->table('cache_items')
    ->loggerPath(__DIR__ . '/logs/cache.log')
    ->expirationTime('30 minutes')
    ->flushAfter('7 days')
    ->build();
```

The `expirationTime()` and `flushAfter()` methods also support the **TimeBuilder** fluent API:

```php
$options = OptionBuilder::forFile()
    ->dir(__DIR__ . '/cache')
    ->expirationTime()->hour(2)
    ->flushAfter()->day(1)
    ->build();
```

---

## Encryption & Compression

### Encryption

Protect sensitive cached data with **AES-256-CBC** encryption. Each value gets a unique random IV — no two ciphertexts are alike, even for the same input.

```php
$cache->useEncryption('your-secret-key-here');
$cache->putCache('token', 'sensitive-data');

// Stored encrypted, decrypted transparently on read
$token = $cache->getCache('token'); // "sensitive-data"
```

### Compression

Reduce storage size with gzip compression — useful for large cached payloads:

```php
$cache->useCompression();
$cache->putCache('large-dataset', $hugeArray);
```

Both can be combined:

```php
$cache->useCompression();
$cache->useEncryption('my-key');

// Data is compressed, then encrypted before storage
$cache->putCache('secure-payload', $data);
```

---

## Tags & Namespaces

### Tags

Group related cache entries and invalidate them all at once:

```php
$cache->putCache('user:1', $userData);
$cache->putCache('user:2', $otherUser);
$cache->tag('users', 'user:1', 'user:2');

// Later — flush everything tagged "users"
$cache->flushTag('users');
```

### Namespaces

Logically separate cache entries to avoid key collisions:

```php
$cache->putCache('config', $appConfig, 'app');
$cache->putCache('config', $apiConfig, 'api');

$cache->getCache('config', 'app'); // $appConfig
$cache->getCache('config', 'api'); // $apiConfig
```

---

## PSR-16 SimpleCache

Need a standards-compliant interface? Wrap any `Cacheer` instance with the PSR-16 adapter:

```php
use Silviooosilva\CacheerPhp\Psr\Psr16CacheAdapter;

$cache = new Cacheer(['cacheDir' => __DIR__ . '/cache']);
$cache->setDriver()->useFileDriver();

$psr16 = new Psr16CacheAdapter($cache);

$psr16->set('key', 'value', 3600);
$psr16->get('key');               // "value"
$psr16->get('missing', 'default'); // "default"
$psr16->delete('key');
$psr16->has('key');                // false

// Batch operations
$psr16->setMultiple(['a' => 1, 'b' => 2]);
$psr16->getMultiple(['a', 'b', 'c'], 'default');
$psr16->deleteMultiple(['a', 'b']);
$psr16->clear();
```

This adapter works with any library that accepts `Psr\SimpleCache\CacheInterface`.

---

## Formatter

Transform cached data on retrieval with the built-in formatter:

```php
$cache->useFormatter();

$json   = $cache->getCache('user:1')->toJson();    // JSON string
$array  = $cache->getCache('user:1')->toArray();   // Array
$object = $cache->getCache('user:1')->toObject();  // stdClass
$string = $cache->getCache('user:1')->toString();  // String cast
```

---

## API Reference

### Write Operations

| Method | Returns | Description |
|---|---|---|
| `putCache($key, $data, $ns, $ttl)` | `bool` | Store a value |
| `add($key, $data, $ns, $ttl)` | `bool` | Store only if key doesn't exist |
| `putMany($items, $ns, $batch)` | `bool` | Store multiple key-value pairs |
| `forever($key, $data)` | `bool` | Store with no expiration |
| `appendCache($key, $data, $ns)` | `bool` | Append to an existing value |
| `increment($key, $amount, $ns)` | `bool` | Increment a numeric value |
| `decrement($key, $amount, $ns)` | `bool` | Decrement a numeric value |
| `renewCache($key, $ttl, $ns)` | `bool` | Refresh a key's TTL |
| `remember($key, $ttl, $fn)` | `mixed` | Get or compute and store |
| `rememberForever($key, $fn)` | `mixed` | Get or compute and store forever |

### Read Operations

| Method | Returns | Description |
|---|---|---|
| `getCache($key, $ns)` | `mixed` | Retrieve a cached value |
| `getMany($keys, $ns)` | `array` | Retrieve multiple values |
| `getAll($ns)` | `array` | Retrieve all values in namespace |
| `getAndForget($key, $ns)` | `mixed` | Retrieve and delete (atomic pop) |
| `has($key, $ns)` | `bool` | Check if a key exists |

### Delete Operations

| Method | Returns | Description |
|---|---|---|
| `clearCache($key, $ns)` | `bool` | Delete a single entry |
| `flushCache()` | `bool` | Delete all entries |
| `tag($tag, ...$keys)` | `bool` | Associate keys with a tag |
| `flushTag($tag)` | `bool` | Delete all entries for a tag |

### Configuration & State

| Method | Returns | Description |
|---|---|---|
| `setDriver()` | `CacheDriver` | Switch storage backend |
| `setConfig()` | `CacheConfig` | Access configuration |
| `useEncryption($key)` | `Cacheer` | Enable AES-256 encryption |
| `useCompression($on)` | `Cacheer` | Enable gzip compression |
| `useFormatter()` | `void` | Enable output formatter |
| `getOption($key, $default)` | `mixed` | Get a config option |
| `getOptions()` | `array` | Get all config options |
| `setOption($key, $value)` | `Cacheer` | Set a config option |
| `stats()` | `array` | Driver, compression & encryption status |
| `isSuccess()` | `bool` | Last operation succeeded? |
| `getMessage()` | `string` | Human-readable status message |

### TTL Formats

CacheerPHP accepts TTL in multiple formats:

```php
$cache->putCache('key', 'value', '', 3600);            // Seconds (int)
$cache->putCache('key', 'value', '', '2 hours');        // Human-readable string
$cache->putCache('key', 'value', '', new DateInterval('PT2H')); // DateInterval
$cache->forever('key', 'value');                        // No expiration
```

---

## Requirements

- **PHP 8.2+**
- `ext-pdo` — for database drivers
- `ext-openssl` — for encryption
- `ext-zlib` — for compression
- Redis server — when using the Redis driver

---

## Testing

```sh
composer install
vendor/bin/phpunit
```

---

## Documentation

Full documentation is available at [CacheerPHP Documentation](https://github.com/CacheerPHP/docs).

---

## Contributing

Contributions are welcome! Please open an issue or submit a pull request.

---

## License

CacheerPHP is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---

## Support

If CacheerPHP saves you time, consider supporting the project:

<p>
  <a href="https://buymeacoffee.com/silviooosilva">
    <img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" height="50" width="210" alt="Buy me a coffee"/>
  </a>
</p>
