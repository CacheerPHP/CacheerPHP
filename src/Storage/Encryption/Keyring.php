<?php

declare(strict_types=1);

namespace Silviooosilva\CacheerPhp\Storage\Encryption;

use InvalidArgumentException;
use Silviooosilva\CacheerPhp\Exceptions\UnsupportedEnvelopeException;

/**
 * A set of named encryption keys with one active key.
 *
 * New writes use the active key; reads look the key up by the id recorded in
 * the envelope, so a rotated-out key can still decrypt older entries as long as
 * it remains in the ring. Key ids are metadata (they travel in cleartext), not
 * secrets, so they must be envelope-safe ASCII.
 */
final class Keyring
{
    private const KEY_BYTES = 32;

    /**
     * @var array<string, string>
     */
    private array $keys;

    /**
     * @var string
     */
    private string $activeId;

    /**
     * @param array<string, string> $keys Map of key id to a raw 32-byte key.
     * @param string $activeId
     */
    public function __construct(array $keys, string $activeId)
    {
        if ($keys === []) {
            throw new InvalidArgumentException('A keyring must contain at least one key.');
        }

        foreach ($keys as $id => $key) {
            self::assertValidId((string) $id);
            if (strlen($key) !== self::KEY_BYTES) {
                throw new InvalidArgumentException(sprintf(
                    'Encryption key "%s" must be exactly %d bytes.',
                    $id,
                    self::KEY_BYTES,
                ));
            }
        }

        if (!array_key_exists($activeId, $keys)) {
            throw new InvalidArgumentException(sprintf('Active key "%s" is not present in the keyring.', $activeId));
        }

        $this->keys = $keys;
        $this->activeId = $activeId;
    }

    /**
     * Derive keys from human-chosen passphrases via SHA-256.
     *
     * @param array<string, string> $passphrases Map of key id to passphrase.
     * @param string $activeId
     * @return Keyring
     */
    public static function fromPassphrases(array $passphrases, string $activeId): self
    {
        $keys = [];
        foreach ($passphrases as $id => $passphrase) {
            $keys[(string) $id] = hash('sha256', $passphrase, true);
        }

        return new self($keys, $activeId);
    }

    /**
     * @return string
     */
    public function activeId(): string
    {
        return $this->activeId;
    }

    /**
     * @return string
     */
    public function activeKey(): string
    {
        return $this->keys[$this->activeId];
    }

    /**
     * @param string $id
     * @return string
     */
    public function keyFor(string $id): string
    {
        if (!array_key_exists($id, $this->keys)) {
            throw UnsupportedEnvelopeException::stage('encryption key', $id);
        }

        return $this->keys[$id];
    }

    /**
     * @param string $id
     */
    private static function assertValidId(string $id): void
    {
        if ($id === '' || preg_match('/[^\x20-\x7E]/', $id) === 1) {
            throw new InvalidArgumentException('Key ids must be non-empty printable ASCII.');
        }
    }
}
