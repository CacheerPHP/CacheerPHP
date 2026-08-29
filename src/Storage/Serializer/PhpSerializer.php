<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage\Serializer;

use Silviooosilva\CacheerPhp\Contracts\Serializer;
use Silviooosilva\CacheerPhp\Exceptions\CorruptedPayloadException;

/**
 * Native PHP serialization. Preserves object types and is the compatibility
 * default. Object restoration can be constrained with an allow-list, mirroring
 * unserialize()'s allowed_classes option.
 */
final class PhpSerializer implements Serializer
{
    /**
     * @var bool|list<class-string>
     */
    private bool|array $allowedClasses;

    /**
     * @param bool|list<class-string> $allowedClasses true allows all classes, false allows none,
     *                                                 or a list restricts restoration to those classes.
     */
    public function __construct(bool|array $allowedClasses = true)
    {
        $this->allowedClasses = $allowedClasses;
    }

    /**
     * @return string
     */
    public function id(): string
    {
        return 'php';
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function serialize(mixed $value): string
    {
        return serialize($value);
    }

    /**
     * @param string $payload
     * @return mixed
     */
    public function unserialize(string $payload): mixed
    {
        if ($payload === 'b:0;') {
            return false;
        }

        $value = @unserialize($payload, ['allowed_classes' => $this->allowedClasses]);

        if ($value === false) {
            throw CorruptedPayloadException::unserializationFailed($this->id());
        }

        return $value;
    }
}
