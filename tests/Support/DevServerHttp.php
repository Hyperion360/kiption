<?php // tests/Support/DevServerHttp.php
namespace App\Tests\Support;

/**
 * The dev-server scaffolding shared by the tests that boot PHP's built-in
 * server against a router script (the front-controller passthrough suite and
 * the shared-host release suite): free-port discovery, process boot, and the
 * stream-context HTTP reads. One copy, so a server-behavior change fixes
 * every consumer at once (the four-copies finding).
 */
trait DevServerHttp
{
    private function freePort(int $start): int
    {
        for ($port = $start; $port < $start + 8; $port++) {
            $sock = @fsockopen('127.0.0.1', $port, timeout: 0.2);
            if ($sock === false) {
                return $port;
            }
            fclose($sock);
        }
        $this->fail('no free port in the ad-hoc range');
    }

    /** @return resource the proc_open handle of the built-in server */
    private function startServer(int $port, string $docroot, string $router): mixed
    {
        $handle = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        $this->assertIsResource($handle, 'dev server failed to start');
        for ($i = 0; $i < 50; $i++) {
            if (@fsockopen('127.0.0.1', $port, timeout: 0.2) !== false) {
                return $handle;
            }
            usleep(100000);
        }
        proc_terminate($handle);
        $this->fail('dev server never came up');
    }

    /** @return array{0: int, 1: string, 2: array<string,string>, 3: string} status, Content-Type, lower-cased header map, body */
    private function httpFetch(int $port, string $path): array
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        $status = 0;
        $type = '';
        $headers = [];
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            } elseif (str_contains($header, ':')) {
                [$name, $value] = explode(':', $header, 2);
                $headers[strtolower(trim($name))] = trim($value);
                if (strcasecmp(trim($name), 'Content-Type') === 0) {
                    $type = trim($value);
                }
            }
        }
        return [$status, $type, $headers, $body === false ? '' : $body];
    }

    private function httpStatus(int $port, string $path): int
    {
        return $this->httpFetch($port, $path)[0];
    }

    private function httpBody(int $port, string $path): string|false
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        return @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
    }

    private function httpHeader(int $port, string $path, string $name): string
    {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        foreach ($http_response_header ?? [] as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return '';
    }
}
