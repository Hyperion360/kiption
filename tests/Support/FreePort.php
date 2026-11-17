<?php // tests/Support/FreePort.php
namespace App\Tests\Support;

/** A TCP port the operating system just confirmed is free on loopback.
 *
 *  Asking for port 0 makes the OS pick one from its ephemeral range, so two
 *  parallel workers cannot be handed the same fixed port (the old scan from
 *  8095 could). The ephemeral range also stays clear of the ports reserved
 *  for the long-lived dev servers (8090, 8093). */
final class FreePort
{
    public static function get(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
        if ($sock === false) throw new \RuntimeException("no free loopback port: {$err}");
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
        fclose($sock);
        return $port;
    }
}
