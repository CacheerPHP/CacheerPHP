<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Stores\Support;

use Silviooosilva\CacheerPhp\Kernel\Key;
use Silviooosilva\CacheerPhp\Kernel\Scope;

/**
 * The byte layout a persistent store keeps for one entry, independent of the
 * backend (a file's contents, a Redis string value, ...).
 *
 * A length-prefixed JSON header (creation time, expiry, scope segments, key
 * name) precedes the encoded value blob, so the store can reconstruct the Key,
 * evaluate expiry against its own clock, and answer scope/inspection queries
 * without decoding the value.
 */
final class StoredRecord
{
    /**
     * @param list<string> $scopeSegments
     */
    public function __construct(
        public readonly int $createdAt,
        public readonly ?int $expiresAt,
        public readonly array $scopeSegments,
        public readonly string $keyValue,
        public readonly string $blob,
    ) {
    }

    public static function forKey(Key $key, int $createdAt, ?int $expiresAt, string $blob): self
    {
        return new self($createdAt, $expiresAt, $key->scope()->segments(), $key->value(), $blob);
    }

    public function key(): Key
    {
        return Key::named($this->keyValue)->within(Scope::fromSegments($this->scopeSegments));
    }

    public function toString(): string
    {
        $header = json_encode([
            'c' => $this->createdAt,
            'e' => $this->expiresAt,
            's' => $this->scopeSegments,
            'k' => $this->keyValue,
        ], JSON_THROW_ON_ERROR);

        return pack('N', strlen($header)) . $header . $this->blob;
    }

    public static function fromString(string $raw): ?self
    {
        if (strlen($raw) < 4) {
            return null;
        }

        /** @var array{1: int} $lengths */
        $lengths = unpack('N', substr($raw, 0, 4));
        $headerLength = $lengths[1];
        if (strlen($raw) < 4 + $headerLength) {
            return null;
        }

        $header = json_decode(substr($raw, 4, $headerLength), true);
        if (!is_array($header) || !array_key_exists('c', $header)) {
            return null;
        }

        /** @var array{c: int, e: int|null, s: list<string>, k: string} $header */
        return new self(
            $header['c'],
            $header['e'],
            $header['s'],
            $header['k'],
            substr($raw, 4 + $headerLength),
        );
    }
}
