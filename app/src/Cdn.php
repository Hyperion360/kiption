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
     *  @param ?\Closure(string $url, string $body, string $token): bool $transport
     *      test seam; the default is the raw-HTTPS POST below */
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
        $body = (string) json_encode(['prefixes' => [$hostPrefix]]);
        try {
            return ($this->transport ?? $this->post(...))($url, $body, $token);
        } catch (\Throwable) {
            return false; // a stale edge for one TTL window, not a failed write
        }
    }

    /** The raw transport: one POST, 5 seconds total, a non-2xx answer (or a
     *  connect failure) is false. TLS peer verification stays on. */
    private function post(string $url, string $body, string $token): bool
    {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$token}",
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true, // a 4xx/5xx body must not raise; we read the status
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        if (@file_get_contents($url, false, $ctx) === false) return false;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1
                && (int) $m[1] >= 200 && (int) $m[1] < 300) return true;
        }
        return false;
    }
}
