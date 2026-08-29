<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage\KeyEncoder;

use Silviooosilva\CacheerPhp\Contracts\KeyEncoder;
use Silviooosilva\CacheerPhp\Kernel\Key;

/**
 * Encodes a Key as a readable prefix plus a hash of its full identity.
 *
 * The prefix keeps encoded keys diagnosable in a filesystem or Redis keyspace;
 * the hash guarantees a bounded length and a backend-safe character set while
 * preserving the Key's collision-free identity. An optional namespace prefix
 * scopes an application's keys inside a shared backend.
 */
final class HashingKeyEncoder implements KeyEncoder
{
    private const ALGO = 'sha256';

    private const READABLE_MAX = 40;

    /**
     * @param string $prefix
     */
    public function __construct(private readonly string $prefix = '')
    {
    }

    /**
     * @param Key $key
     * @return string
     */
    public function encode(Key $key): string
    {
        $hash = hash(self::ALGO, $key->identity());
        $readable = $this->readable((string) $key);
        $encoded = $readable === '' ? $hash : $readable . '.' . $hash;

        return $this->prefix === '' ? $encoded : $this->prefix . ':' . $encoded;
    }

    /**
     * @param string $value
     * @return string
     */
    private function readable(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $value) ?? '';
        $slug = trim($slug, '_');

        return substr($slug, 0, self::READABLE_MAX);
    }
}
