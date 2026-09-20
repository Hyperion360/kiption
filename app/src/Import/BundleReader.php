<?php // app/src/Import/BundleReader.php
namespace App\Import;

/** Streaming reader for an efiction-export bundle. Extracts once to a temp dir
 *  (cleaned on destruct), iterates archive.jsonl.gz line by line (never loads
 *  the archive in memory), exposes the manifest and story files. Mapping is
 *  NOT this class's job (the 9b mapper owns it). */
final class BundleReader
{
    private string $dir;
    private ?array $manifestCache = null;

    public function __construct(private string $bundlePath)
    {
        if (!is_file($this->bundlePath)) throw new \RuntimeException('bundle not found');
        $this->dir = sys_get_temp_dir() . '/kiption-read-' . bin2hex(random_bytes(6));
        try {
            $tar = new \PharData($this->bundlePath);
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

    /** @return \Generator<int, array{table: string, row: array}> */
    public function rows(): \Generator
    {
        $h = gzopen($this->dir . '/archive.jsonl.gz', 'rb');
        try {
            while (($line = gzgets($h)) !== false) {
                $line = trim($line);
                if ($line === '') continue;
                $obj = json_decode($line, true);
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
