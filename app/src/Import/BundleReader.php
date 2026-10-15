<?php // app/src/Import/BundleReader.php
namespace App\Import;

/** Streaming reader for an efiction-export bundle. Extracts once to a temp dir
 *  (cleaned on destruct), iterates archive.jsonl.gz line by line (never loads
 *  the archive in memory), exposes the manifest and story files. Mapping is
 *  NOT this class's job (the 9b mapper owns it). */
final class BundleReader
{
    /** Hostile-bundle bounds (the pentest T6 findings): a legacy export for a
     *  large archive stays orders of magnitude under both. */
    private const MAX_MEMBERS = 200000;
    private const MAX_UNCOMPRESSED_BYTES = 8 * 1024 * 1024 * 1024; // 8 GiB
    /** One archive row above this length is a decompression bomb, not data:
     *  gzgets without a bound reads the whole member into memory. */
    private const MAX_ROW_BYTES = 1 << 20;

    private string $dir;
    private ?array $manifestCache = null;

    public function __construct(private string $bundlePath)
    {
        if (!is_file($this->bundlePath)) throw new \RuntimeException('bundle not found');
        $this->dir = sys_get_temp_dir() . '/kiption-read-' . bin2hex(random_bytes(6));
        try {
            $tar = new \PharData($this->bundlePath);
            // Pre-scan before extracting (the pentest T6 tar-bomb finding): a
            // hostile bundle must be rejected before it fills the temp
            // filesystem, not after.
            $count = 0;
            $bytes = 0;
            foreach (new \RecursiveIteratorIterator($tar) as $member) {
                if (++$count > self::MAX_MEMBERS) {
                    throw new \RuntimeException('bundle holds more than ' . self::MAX_MEMBERS . ' members');
                }
                $bytes += $member->getSize();
                if ($bytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new \RuntimeException('bundle decompresses past ' . self::MAX_UNCOMPRESSED_BYTES . ' bytes');
                }
            }
            $tar->extractTo($this->dir, null, true);
            if (!is_file($this->dir . '/manifest.json') || !is_file($this->dir . '/archive.jsonl.gz')) {
                throw new \RuntimeException('bundle is missing manifest.json or archive.jsonl.gz');
            }
        } catch (\Throwable $e) {
            // the destructor never runs when the constructor throws: every
            // throw path below must clean the extracted temp dir itself
            exec('rm -rf ' . escapeshellarg($this->dir)); // finding 14: ctor-throw must not leak the temp dir
            throw $e instanceof \RuntimeException ? $e
                : new \RuntimeException('not a valid export bundle: ' . $e->getMessage(), 0, $e);
        }
    }

    public function __destruct()
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function manifest(): array
    {
        return $this->manifestCache ??= json_decode((string) file_get_contents($this->dir . '/manifest.json'), true);
    }

    /** Absolute path of the bundle file itself: the Importer writes its
     *  pre-import snapshot next to it (finding 12). */
    public function bundlePath(): string
    {
        return $this->bundlePath;
    }

    /** @return \Generator<int, array{table: string, row: array}> */
    public function rows(): \Generator
    {
        $h = gzopen($this->dir . '/archive.jsonl.gz', 'rb');
        try {
            $n = 0;
            while (($line = gzgets($h, self::MAX_ROW_BYTES)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $n++;
                // gzgets returns exactly the cap minus one bytes without the
                // delimiter when a row is longer: a newline-free member is a
                // decompression bomb, not data.
                if (strlen($line) === self::MAX_ROW_BYTES - 1 && !str_ends_with($line, "\n")) {
                    throw new \RuntimeException("corrupt archive row #{$n}: row exceeds " . self::MAX_ROW_BYTES . " bytes");
                }
                $obj = json_decode($line, true);
                // a truncated or doctored archive must fail loudly but as a
                // controlled error, not a TypeError deep in the generator
                if (!is_array($obj) || !array_key_exists('_table', $obj)) {
                    throw new \RuntimeException("corrupt archive row #{$n}: " . substr($line, 0, 60));
                }
                yield ['table' => (string) $obj['_table'], 'row' => array_diff_key($obj, ['_table' => 1])];
            }
        } finally { gzclose($h); }
    }

    public function storyFile(int $uid, int $chapid): ?string
    {
        $p = $this->dir . '/stories/' . $uid . '/' . $chapid . '.txt';
        return is_file($p) ? (string) file_get_contents($p) : null;
    }
}
