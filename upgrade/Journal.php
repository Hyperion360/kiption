<?php // upgrade/Journal.php
declare(strict_types=1);

namespace Upgrade;

/**
 * The private upgrade journal: one 0600 JSON file at
 * site/shared/upgrade/journal.json recording exactly where an upgrade
 * stands, the immutable source and target release IDs, the target's
 * verified version, and the browser capability record. The raw capability
 * NEVER lands here, only its SHA-256 and an expiry; every write is a
 * whole-file temp+rename, so a killed process leaves either the previous
 * journal or the new one, never a partial one.
 *
 * The coordinator (switch, migrate, and restore states) extends the state
 * set; this file owns the record, not the transitions.
 *
 * Test seam: options['now'] fixes the clock. Production never sets it.
 */
final class Journal
{
    public const CAPABILITY_TTL = 3600;
    public const PREPARED = 'prepared';
    public const FILE = 'upgrade/journal.json';

    /** @var list<string> states this engine can record */
    private const STATES = [self::PREPARED];

    /** @param array<string,mixed> $options */
    public function __construct(private string $sharedDir, private array $options = []) {}

    public function path(): string
    {
        return $this->sharedDir . '/' . self::FILE;
    }

    /** The decoded journal, or null when none is recorded. */
    public function read(): ?array
    {
        $raw = @file_get_contents($this->path());
        if ($raw === false) {
            return null;
        }
        $journal = json_decode($raw, true);
        return is_array($journal) ? $journal : null;
    }

    public function state(): ?string
    {
        $journal = $this->read();
        return $journal === null ? null : (string) ($journal['state'] ?? '');
    }

    /**
     * Records a prepared upgrade and, for the browser path, mints the
     * capability: the caller stores the returned raw value in the owner's
     * session only. The CLI path mints none, because filesystem ownership
     * is CLI authority; an owner who prepared on the CLI and wants a
     * browser continue bridges with a recovery token.
     *
     * @return array{0:array<string,mixed>,1:?string} journal, raw capability or null
     */
    public function prepare(string $sourceId, string $targetId, string $targetVersion, bool $web): array
    {
        if ($this->read() !== null) {
            throw new \RuntimeException('an upgrade is already recorded; recover or clear it first');
        }
        $journal = [
            'state' => self::PREPARED,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'target_version' => $targetVersion,
            'created_at' => date('c', $this->now()),
        ];
        $capability = null;
        if ($web) {
            $capability = bin2hex(random_bytes(32));
            $journal['capability_hash'] = hash('sha256', $capability);
            $journal['capability_expires'] = $this->now() + self::CAPABILITY_TTL;
        }
        $this->write($journal);
        return [$journal, $capability];
    }

    /** The presented capability against the recorded hash, expiry included. */
    public function capabilityValid(?string $presented): bool
    {
        $journal = $this->read();
        if ($journal === null || !is_string($presented) || $presented === '') {
            return false;
        }
        $hash = (string) ($journal['capability_hash'] ?? '');
        $expires = (int) ($journal['capability_expires'] ?? 0);
        return $hash !== ''
            && preg_match('/^[0-9a-f]{64}$/', $presented) === 1
            && $expires > $this->now()
            && hash_equals($hash, hash('sha256', $presented));
    }

    /**
     * The file-manager recovery bridge: the owner places a fresh 64-hex
     * secret at site/shared/upgrade/recovery-token through the hosting file
     * manager (never a URL); this consumes it once, compared with
     * hash_equals and then unlinked, and mints a new capability.
     *
     * @return string the new raw capability (session storage only)
     */
    public function rotateWithRecoveryToken(string $presented): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $presented) !== 1) {
            throw new \RuntimeException('the recovery token is malformed');
        }
        $stored = @file_get_contents($this->sharedDir . '/upgrade/recovery-token');
        if ($stored === false || !hash_equals(trim($stored), $presented)) {
            throw new \RuntimeException('the recovery token does not match');
        }
        $journal = $this->read();
        if ($journal === null) {
            throw new \RuntimeException('no upgrade is recorded to recover');
        }
        @unlink($this->sharedDir . '/upgrade/recovery-token');
        $capability = bin2hex(random_bytes(32));
        $journal['capability_hash'] = hash('sha256', $capability);
        $journal['capability_expires'] = $this->now() + self::CAPABILITY_TTL;
        $this->write($journal);
        return $capability;
    }

    /**
     * Whole-file journal write: the state and ID shapes are validated and
     * the release IDs are immutable against whatever is already recorded.
     */
    public function write(array $journal): void
    {
        if (!in_array($journal['state'] ?? '', self::STATES, true)) {
            throw new \RuntimeException('refusing to record an unknown upgrade state');
        }
        foreach (['source_id', 'target_id'] as $key) {
            if (preg_match('/^[0-9a-f]{64}$/', (string) ($journal[$key] ?? '')) !== 1) {
                throw new \RuntimeException('the journal requires 64-hex release IDs');
            }
        }
        $existing = $this->read();
        if ($existing !== null) {
            foreach (['source_id', 'target_id'] as $key) {
                if (($existing[$key] ?? null) !== ($journal[$key] ?? null)) {
                    throw new \RuntimeException('release IDs are immutable once recorded');
                }
            }
        }
        $journal['updated_at'] = date('c', $this->now());
        $dir = dirname($this->path());
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create the private upgrade directory');
        }
        $bytes = (string) json_encode($journal, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $tmp = $this->path() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $bytes) === false) {
            @unlink($tmp);
            throw new \RuntimeException('cannot write the upgrade journal');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $this->path())) {
            @unlink($tmp);
            throw new \RuntimeException('cannot finalize the upgrade journal');
        }
    }

    /** Removes the journal entirely (a prepared upgrade that never switched). */
    public function clear(): void
    {
        @unlink($this->path());
    }

    private function now(): int
    {
        return (int) ($this->options['now'] ?? time());
    }
}
