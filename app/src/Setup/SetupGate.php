<?php // app/src/Setup/SetupGate.php
declare(strict_types=1);

namespace App\Setup;

/**
 * The one-use setup gate. It owns setup authorization and setup state, and
 * nothing else: no HTTP, no migrations, no mail. The token is an operator-
 * created 64-character hex secret stored in the private data directory,
 * outside the web root; no secret ever ships in the release ZIP. The lock is
 * the OS lock on a persistent private file, never the file's existence: a
 * killed worker releases it, and every mutation re-checks the world after
 * taking it.
 */
final class SetupGate
{
    public const TOKEN = 'setup-token.txt';
    public const COMPLETE = 'setup-complete.lock';
    public const LOCK = 'setup.lock';
    public const ATTEMPTS = 'setup-attempts';
    /** The attempt window: ten failures per ten minutes; token entropy is the real defense. */
    private const WINDOW_SECONDS = 600;
    private const WINDOW_MAX = 10;

    /** @return array<string,string> session_start() cookie options for the setup endpoint */
    public static function sessionCookieParams(): array
    {
        return ['secure' => true, 'httponly' => true, 'samesite' => 'Lax'];
    }

    private static function dataDir(array $config): string
    {
        return (string) ($config['data_dir'] ?? $config['app_dir'] ?? '');
    }

    public static function path(array $config, string $file): string
    {
        return self::dataDir($config) . '/' . $file;
    }

    public static function tokenExists(array $config): bool
    {
        return is_file(self::path($config, self::TOKEN));
    }

    public static function isComplete(array $config): bool
    {
        return is_file(self::path($config, self::COMPLETE));
    }

    public static function hasAdmin(array $config): bool
    {
        try {
            return Install::hasAdmin($config); // never creates the database to ask
        } catch (\Throwable) {
            return false; // an unmigrated database has no admin yet
        }
    }

    /**
     * The single pending-state predicate, file checks only (no database): the
     * front controller's 503 gate runs it on every request, so it must never
     * open the content database. While the operator's token waits and the
     * completion marker has not landed, routed pages return a generic 503;
     * setup.php itself stays reachable as a real file.
     */
    public static function pending(array $config): bool
    {
        return self::tokenExists($config) && !self::isComplete($config);
    }

    /**
     * Full setup state. available: a token waits and nothing blocks it.
     * recovery: an admin exists but completion never landed, so only the
     * owner's credentials may finalize.
     *
     * @return array{available:bool, recovery:bool, complete:bool, token:bool, admin:bool}
     */
    public static function state(array $config): array
    {
        $complete = self::isComplete($config);
        $admin = self::hasAdmin($config);
        $token = self::tokenExists($config);
        return [
            'token' => $token,
            'complete' => $complete,
            'admin' => $admin,
            'available' => $token && !$complete && !$admin,
            'recovery' => $admin && !$complete,
        ];
    }

    /** The token check: exactly 64 lowercase hex, compared with hash_equals. */
    public static function authorize(array $config, string $supplied): bool
    {
        if (preg_match('/^[0-9a-f]{64}$/', $supplied) !== 1) {
            return false;
        }
        $stored = @file_get_contents(self::path($config, self::TOKEN));
        if ($stored === false) {
            return false;
        }
        if (self::isComplete($config) || self::hasAdmin($config)) {
            return false;
        }
        return hash_equals(trim($stored), $supplied);
    }

    /**
     * Take the exclusive mutation lock without blocking. The file's existence
     * is never the lock; a killed worker releases the OS lock automatically.
     *
     * @return resource|null
     */
    public static function acquireLock(array $config)
    {
        $handle = @fopen(self::path($config, self::LOCK), 'c');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return $handle;
    }

    /**
     * The under-the-lock recheck: a second request that passed an earlier
     * check before the first request completed is refused here.
     */
    public static function holdsAfterRecheck(array $config, string $supplied): bool
    {
        return self::authorize($config, $supplied);
    }

    /**
     * Close setup forever: the completion marker lands atomically first, and
     * only then the token is removed, so no window admits a second install.
     */
    public static function complete(array $config): void
    {
        $marker = self::path($config, self::COMPLETE);
        $tmp = $marker . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, 'setup complete ' . date('c') . "\n") === false) {
            throw new \RuntimeException('cannot write the completion marker');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $marker)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot finalize the completion marker');
        }
        @unlink(self::path($config, self::TOKEN));
    }

    /** Failures inside the window; $now is a test seam for window expiry. */
    public static function failures(array $config, ?int $now = null): int
    {
        $now ??= time();
        $raw = @file_get_contents(self::path($config, self::ATTEMPTS));
        if ($raw === false) {
            return 0;
        }
        $state = json_decode($raw, true);
        if (!is_array($state) || ($state['window_start'] ?? 0) + self::WINDOW_SECONDS <= $now) {
            return 0;
        }
        return (int) ($state['count'] ?? 0);
    }

    public static function recordFailure(array $config, ?int $now = null): void
    {
        $now ??= time();
        $count = self::failures($config, $now);
        $state = ['count' => $count + 1, 'window_start' => $count === 0 ? $now : self::windowStart($config, $now)];
        $handle = @fopen(self::path($config, self::ATTEMPTS), 'c');
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode($state));
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public static function rateLimited(array $config, ?int $now = null): bool
    {
        return self::failures($config, $now) >= self::WINDOW_MAX;
    }

    private static function windowStart(array $config, int $now): int
    {
        $raw = @file_get_contents(self::path($config, self::ATTEMPTS));
        $state = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($state) ? (int) ($state['window_start'] ?? $now) : $now;
    }
}
