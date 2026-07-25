<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage\Serializer;

use JsonException;
use Silviooosilva\CacheerPhp\Contracts\Serializer;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;

/**
 * JSON serialization: portable and human-readable, at the cost of type
 * fidelity. Objects are restored as associative arrays, so this suits scalar
 * and array-shaped cache values rather than rich object graphs.
 */
final class JsonSerializer implements Serializer
{
    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function id(): string
    {
        return 'json';
    }

    public function serialize(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }

    public function unserialize(string $payload): mixed
    {
        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw CorruptedPayloadException::unserializationFailed($this->id());
        }
    }
}
