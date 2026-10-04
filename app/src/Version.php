<?php // app/src/Version.php
declare(strict_types=1);

namespace App;

/**
 * Single source of the app version; CHANGELOG.md is the narrative. The
 * version arm also answers "which framework am I running": the locked
 * kip/framework pin, parsed from composer.lock and rendered by pin type,
 * so a support report names the whole stack.
 */
final class Version
{
    public const VERSION = '0.1.0-dev';

    public const FRAMEWORK_REMOTE = 'https://github.com/Hyperion360/kip';

    /** @return array<string, mixed>|null the kip/framework entry, scanning packages AND packages-dev */
    private static function frameworkEntry(string $lockFile): ?array
    {
        $lock = is_file($lockFile) ? json_decode((string) file_get_contents($lockFile), true) : null;
        if (!is_array($lock)) return null;
        foreach (['packages', 'packages-dev'] as $section) {
            foreach ($lock[$section] ?? [] as $p) {
                if (is_array($p) && ($p['name'] ?? '') === 'kip/framework') return $p;
            }
        }
        return null;
    }

    /** The locked framework pin, rendered by entry type: a git dev pin
     *  prints version@hash12 (dev-main@2979b924372e), a path repository
     *  prints version + path (the path-repo convention has no git reference
     *  to show), a tag pin prints the tag. */
    public static function frameworkLock(string $lockFile): string
    {
        $p = self::frameworkEntry($lockFile);
        if ($p === null) return '(kip/framework not found in composer.lock)';
        $version = (string) ($p['version'] ?? '');
        if (($p['dist']['type'] ?? '') === 'path') {
            return trim("{$version} " . (string) ($p['dist']['url'] ?? ''));
        }
        if ($version !== '' && !str_starts_with($version, 'dev-')) {
            return $version; // a tag pin prints the tag
        }
        $ref = (string) ($p['source']['reference'] ?? $p['dist']['reference'] ?? '');
        return $version . ($ref !== '' ? '@' . substr($ref, 0, 12) : '');
    }

    /**
     * The --check report, one line per finding: STALE/CURRENT against the
     * latest upstream tag. The network is optional: every failure mode
     * (offline, unreachable remote, no tags published yet, a lock with
     * nothing comparable) is a labeled line, never a guess.
     *
     * @return list<string>
     */
    public static function upstreamCheck(string $lockFile, ?string $remote = null): array
    {
        $remote ??= getenv('KIP_FRAMEWORK_REMOTE') ?: self::FRAMEWORK_REMOTE;
        $p = self::frameworkEntry($lockFile);
        if ($p === null) return ['no kip/framework entry in composer.lock; run composer install'];
        if (($p['dist']['type'] ?? '') === 'path') {
            return ['path repository pin (' . (string) ($p['dist']['url'] ?? '') . '); no upstream ref to compare'];
        }
        exec('git ls-remote --tags ' . escapeshellarg($remote) . ' 2>&1', $lines, $code);
        if ($code !== 0) {
            return ["upstream check unavailable: git ls-remote failed for {$remote} (offline, or no access)"];
        }
        $latest = self::latestTag($lines);
        if ($latest === null) {
            return ["upstream has no tags yet ({$remote}); nothing to compare"];
        }
        [$tag, $sha] = $latest;
        $ref = strtolower((string) ($p['source']['reference'] ?? $p['dist']['reference'] ?? ''));
        if ($ref === '') {
            return ["latest upstream tag: {$tag}; the lock entry carries no reference to compare"];
        }
        return [$sha === $ref
            ? "framework pin CURRENT (latest upstream tag {$tag})"
            : "framework pin STALE: latest upstream tag is {$tag}"];
    }

    /** @param list<string> $lines git ls-remote output
     *  @return array{0: string, 1: string}|null [tag name, commit sha] of the
     *  highest version tag; annotated tags resolve through their peeled ^{} line */
    private static function latestTag(array $lines): ?array
    {
        $tags = [];
        foreach ($lines as $line) {
            if (preg_match('#^([0-9a-f]{40})\trefs/tags/v?([0-9][0-9A-Za-z.\-]*)(\^\{\})?$#', trim($line), $m) !== 1) continue;
            $tags[$m[2]] = [$m[2], strtolower($m[1])]; // a peeled line overwrites the tag-object sha
        }
        $names = array_keys($tags);
        usort($names, fn(string $a, string $b): int => version_compare($b, $a));
        return $names === [] ? null : $tags[$names[0]];
    }
}
