<?php // app/src/Export/ZipWriter.php
namespace App\Export;

/** Minimal ZIP writer for STORED (uncompressed) entries, pack() only: the
 *  EPUB container needs mimetype first and uncompressed, and ext-zip is not
 *  a dependency the app can assume (the ~60-lines-of-pack stance). Layout
 *  per the PKWARE APPNOTE: 30-byte local file headers, 46-byte central
 *  directory headers, 22-byte end-of-central-directory.
 *
 *  Width discipline (review finding 13, probed): every field packs
 *  explicitly little-endian and unsigned - V for the 32-bit fields
 *  (signatures, CRC, sizes, offsets), v for the 16-bit ones (versions,
 *  flags, method, DOS time/date, name lengths). Never N (big-endian) and
 *  never L (machine-sized). The CRC comes from hash('crc32b') through
 *  hexdec() because crc32() returns a SIGNED int and pack('V') would emit
 *  the two's-complement bytes for any value >= 0x80000000, silently
 *  corrupting roughly half of all payloads.
 *
 *  Entry names are validated against [#^[A-Za-z0-9/._-]+$#]: the archive is
 *  always app-assembled from fixed paths, so spaces, traversal, and
 *  non-ASCII never belong here and are rejected rather than encoded. */
final class ZipWriter
{
    private const NAME_PATTERN = '#^[A-Za-z0-9/._-]+$#';

    private const LOCAL_SIG = 0x04034b50;
    private const CENTRAL_SIG = 0x02014b50;
    private const EOCD_SIG = 0x06054b50;

    private const METHOD_STORED = 0;
    private const VERSION = 20; // 2.0: the version that introduced directories

    /** @var array<int, array{name: string, crc: int, size: int, offset: int, time: int, date: int}> */
    private array $entries = [];

    private string $out = '';

    /** Append one stored entry. The local header goes out immediately; the
     *  central directory record (with this entry's absolute offset) is kept
     *  for finish(). */
    public function add(string $name, string $data): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('illegal zip entry name: ' . $name);
        }
        // The character whitelist alone still spells "..", and a ".."
        // segment in a downloaded archive is the zip-slip footgun for
        // whatever extracts it. Every name this app assembles is a fixed
        // constant, so no legitimate caller ever needs one.
        if (in_array('..', explode('/', $name), true)) {
            throw new \InvalidArgumentException('zip entry name cannot traverse: ' . $name);
        }
        $crc = hexdec(hash('crc32b', $data));
        $size = strlen($data);
        $nameLen = strlen($name);
        [$dosTime, $dosDate] = self::dosDateTime(time());
        $offset = strlen($this->out);
        $this->out .= pack('VvvvvvVVVvv',
            self::LOCAL_SIG, self::VERSION, 0, self::METHOD_STORED, $dosTime, $dosDate,
            $crc, $size, $size, $nameLen, 0,
        ) . $name . $data;
        $this->entries[] = ['name' => $name, 'crc' => $crc, 'size' => $size, 'offset' => $offset,
            'time' => $dosTime, 'date' => $dosDate];
    }

    /** Emit the central directory plus the EOCD and return the whole archive. */
    public function finish(): string
    {
        $central = '';
        $offset = strlen($this->out);
        foreach ($this->entries as $e) {
            $central .= pack('VvvvvvvVVVvvvvvVV',
                self::CENTRAL_SIG, self::VERSION, self::VERSION, 0, self::METHOD_STORED, $e['time'], $e['date'],
                $e['crc'], $e['size'], $e['size'],
                strlen($e['name']), 0, 0, 0, 0, 0, $e['offset'],
            ) . $e['name'];
        }
        $count = count($this->entries);
        return $this->out . $central . pack('VvvvvVVv',
            self::EOCD_SIG, 0, 0, $count, $count, strlen($central), $offset, 0,
        );
    }

    /** @return array{0: int, 1: int} the DOS time and date words for a Unix timestamp */
    private static function dosDateTime(int $ts): array
    {
        $time = ((int) gmdate('G', $ts) << 11) | ((int) gmdate('i', $ts) << 5) | ((int) gmdate('s', $ts) >> 1);
        $date = (((int) gmdate('Y', $ts) - 1980) << 9) | ((int) gmdate('n', $ts) << 5) | (int) gmdate('j', $ts);
        return [$time, $date];
    }
}
