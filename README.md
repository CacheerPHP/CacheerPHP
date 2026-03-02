# CacheerPHP

<p align="center">
  <a href="https://github.com/silviooosilva/CacheerPHP"><img src="./art/cacheer_php_logo__.png" width="450" alt="CacheerPHP Logo"/></a>
</p>

[![Maintainer](https://img.shields.io/badge/maintainer-@silviooosilva-blue.svg?style=for-the-badge&color=blue)](https://github.com/silviooosilva)
![Packagist Dependency Version](https://img.shields.io/packagist/dependency-v/silviooosilva/cacheer-php/PHP?style=for-the-badge&color=blue)
[![Latest Version](https://img.shields.io/github/release/silviooosilva/CacheerPHP.svg?style=for-the-badge&color=blue)](https://github.com/silviooosilva/CacheerPHP/releases)
[![Quality Score](https://img.shields.io/scrutinizer/g/silviooosilva/CacheerPHP.svg?style=for-the-badge&color=blue)](https://scrutinizer-ci.com/g/silviooosilva/CacheerPHP)
![Packagist Downloads](https://img.shields.io/packagist/dt/silviooosilva/cacheer-php?style=for-the-badge&color=blue)

CacheerPHP is a minimalist PHP caching library with multiple backends (file, database, Redis and array), optional compression/encryption and a small but complete API.

## Features

- Multiple storage drivers: file system, databases (MySQL, PostgreSQL, SQLite), Redis and in-memory arrays.
- TTL, namespaces and tags for organization and invalidation.
- Auto-flush support with `flushAfter`.
- Optional compression and encryption.
- Formatter helper for JSON/array/object output.

## Requirements

- PHP 8.0+
- PDO drivers for database backends
- Redis server when using the Redis driver

## Installation

```sh
composer require silviooosilva/cacheer-php
```

## Configuration

Copy the example environment file and adjust as needed:

```sh
cp .env.example .env
```

Minimum variables:

```env
DB_CONNECTION=sqlite
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

For MySQL/PostgreSQL:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cacheer_db
DB_USERNAME=root
DB_PASSWORD=secret
```

## Quick start

```php
require_once __DIR__ . '/vendor/autoload.php';

use Silviooosilva\CacheerPhp\Cacheer;

Cacheer::setConfig()->setTimeZone('UTC');
Cacheer::setDriver()->useArrayDriver();

Cacheer::putCache('user:1', ['id' => 1, 'name' => 'John']);
if (Cacheer::has('user:1')) {
    $user = Cacheer::getCache('user:1');
}
```

File driver with options:

```php
$cache = new Cacheer([
    'cacheDir' => __DIR__ . '/cache',
    'loggerPath' => __DIR__ . '/cacheer.log',
    'expirationTime' => '1 hour',
    'flushAfter' => '1 day',
]);
```

## Drivers

```php
Cacheer::setDriver()->useFileDriver();
Cacheer::setDriver()->useDatabaseDriver();
Cacheer::setDriver()->useRedisDriver();
Cacheer::setDriver()->useArrayDriver();
```

## Return contract

- `putCache`, `appendCache`, `clearCache`, `flushCache`, `renewCache`, `putMany`, `tag`, `flushTag` return `bool`.
- `has` returns `bool`.
- `getCache` returns `mixed|null` (or `CacheDataFormatter` when formatter is enabled).
- `getMany` returns `array` (or `CacheDataFormatter` when formatter is enabled).
- `getAll` returns `array` (or `CacheDataFormatter` when formatter is enabled).
- `getMessage` returns `string`; `isSuccess` returns `bool`.

Formatter usage:

```php
$cache->useFormatter();
$json = $cache->getCache('user:1')->toJson();
```

## Testing

```sh
vendor/bin/phpunit
```

## Documentation

Full documentation is available at [CacheerPHP Documentation](https://github.com/CacheerPHP/docs).

## Contributing

Contributions are welcome. Please open an issue or submit a pull request.

## License

CacheerPHP is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## Support

If this project helps you, consider [buying the maintainer a coffee](https://buymeacoffee.com/silviooosilva).
<p><a href="https://buymeacoffee.com/silviooosilva"> <img align="left" src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" height="50" width="210" alt="silviooosilva" /></a></p><br><br>
