<?php // app/src/Cdn.php
namespace App;

/**
 * Optional CDN purge against a Cloudflare-compatible purge_cache endpoint.
 * Generic support, default-off: the cdn.* keys in config.php (enabled,
 * zone_id, api_token, purge_host). Fire-and-forget by contract: purge()
 * answers false on any failure instead of throwing, so the deferred call
 * site can never fail the write that queued it. Raw-HTTPS streams only,
 * the Kip Mailer toSmtp idiom (stream context, 5s budget, failures are
 * return values, not exceptions).
 */
final class Cdn
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/';

    /** @param array<string, mixed> $config the cdn.* config block
     *  @param ?\Closure(string $url, string $body, string $token): (int|bool) $transport
     *      test seam; answers the HTTP status (0 for a connect failure). A
     *      plain true counts as 200 and false as 0. The default is the raw
     *      HTTPS POST below. */
    public function __construct(
        private array $config,
        private ?\Closure $transport = null,
    ) {}

    public function purge(string $hostPrefix): bool
    {
        if (!($this->config['enabled'] ?? false)) return false;
        $zone = (string) ($this->config['zone_id'] ?? '');
        $token = (string) ($this->config['api_token'] ?? '');
        if ($zone === '' || $token === '' || $hostPrefix === '') return false;
        $url = self::ENDPOINT . rawurlencode($zone) . '/purge_cache';
        $status = $this->send($url, ['prefixes' => [$hostPrefix]], $token);
        if ($status === 400) {
            // Not every plan accepts a prefix purge; a host purge works on all of them.
            $host = (string) preg_replace('#^[a-z]+://#i', '', $hostPrefix);
            $status = $this->send($url, ['hosts' => [explode('/', $host)[0]]], $token);
        }
        if ($status >= 200 && $status < 300) return true;
        // Status and host only, never the token, URL query or body.
        error_log($status === 429 ? 'cdn purge rate-limited' : 'cdn purge failed: status=' . $status . ' host=' . $hostPrefix);
        return false; // a stale edge for one TTL window, not a failed write
    }

    /** @param array<string, list<string>> $payload */
    private function send(string $url, array $payload, string $token): int
    {
        try {
            $r = ($this->transport ?? $this->post(...))($url, (string) json_encode($payload), $token);
            return is_bool($r) ? ($r ? 200 : 0) : (int) $r;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** The raw transport: one POST, 5 seconds total. Answers the HTTP status,
     *  or 0 on a connect failure. TLS peer verification stays on. */
    private function post(string $url, string $body, string $token): int
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}",
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true, // a 4xx/5xx body must not raise; we read the status
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        if (@file_get_contents($url, false, $ctx) === false) return 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) return (int) $m[1];
        }
        return 0;
    }
}
