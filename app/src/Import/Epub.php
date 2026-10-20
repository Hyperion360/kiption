<?php // app/src/Import/Epub.php
namespace App\Import;
use Kip\Database;

/** The EPUB reader: hostile ZIPs by default (the pentest's tar-bomb finding
 *  is the precedent), so bounds are enforced in two layers. Declared sizes
 *  come from the central directory and every entry passes them BEFORE any
 *  payload is read: member count, per-entry bytes, archive total. Then
 *  actual bytes accumulate across every read and re-enforce the archive
 *  total, so a lying central directory cannot spend reads it never
 *  declared. getFromIndex() returning false is an error, never an empty
 *  string. inspect() is pure reading (no DB); persistence lives in the
 *  import methods. */
final class Epub
{
    public const MAX_MEMBERS = 4096;
    public const MAX_FILE_BYTES = 16 * 1024 * 1024;  // 16 MiB per entry
    public const MAX_TOTAL_BYTES = 64 * 1024 * 1024; // 64 MiB per archive

    private ?\ZipArchive $zip = null;
    /** @var array<int, array<string, mixed>> the central directory stat list
     *  from the last inspect(), kept so structure passes read entries by
     *  index without re-walking the archive */
    private array $stats = [];
    /** actual bytes read since the last inspect(), the accumulated half of
     *  the archive cap */
    private int $actualTotal = 0;

    public function __construct(
        private Database $db,
        private string $uploadsDir,   // cover storage (import half)
    ) {}

    /** Bounds pass: open the archive, walk the central directory once
     *  enforcing the member count, per-entry declared bytes, and the
     *  declared total, then run the structure pass. No entry payload is
     *  read here; a zip that fails any cap is refused without a single
     *  getFromIndex call. */
    public function inspect(string $path): array
    {
        // a directory import inspects many files through one instance
        if ($this->zip !== null) { $this->zip->close(); $this->zip = null; }
        $zip = new \ZipArchive();
        if (($code = $zip->open($path, \ZipArchive::RDONLY)) !== true) {
            throw new \RuntimeException("not a readable zip archive (code {$code})");
        }
        $this->zip = $zip;
        if ($zip->numFiles > self::MAX_MEMBERS) {
            throw new \RuntimeException(sprintf('zip holds %d entries, over the 4096 member cap', $zip->numFiles));
        }
        $this->stats = [];
        $declaredTotal = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new \RuntimeException("zip central directory unreadable at index {$i}");
            }
            $this->stats[] = $stat;
            $size = (int) $stat['size'];
            if ($size > self::MAX_FILE_BYTES) {
                throw new \RuntimeException(sprintf("zip entry '%s' declares %d bytes, over the 16 MiB per-entry cap", $stat['name'], $size));
            }
            $declaredTotal += $size;
            if ($declaredTotal > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException(sprintf('zip entries declare %d bytes total, over the 64 MiB archive cap', $declaredTotal));
            }
        }
        $this->actualTotal = 0;
        return $this->readStructure();
    }

    /** One length-capped payload read. MAX_FILE_BYTES + 1 is asked from the
     *  zip so an oversized entry is DETECTED (strlen past the cap) rather
     *  than silently truncated; a false return is a hard error, never an
     *  empty string. Every successful read accumulates actual bytes against
     *  the archive cap, the actual half of the bounds contract. */
    public function readEntry(int $i): string
    {
        if ($this->zip === null) {
            throw new \RuntimeException('readEntry runs only after inspect() opened the archive');
        }
        $data = $this->zip->getFromIndex($i, self::MAX_FILE_BYTES + 1);
        if ($data === false) {
            throw new \RuntimeException(sprintf("zip entry '%s' (index %d) failed to read", $this->stats[$i]['name'] ?? $i, $i));
        }
        if (strlen($data) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(sprintf('zip entry %d read %d actual bytes, over the 16 MiB per-entry cap', $i, strlen($data)));
        }
        $this->actualTotal += strlen($data);
        if ($this->actualTotal > self::MAX_TOTAL_BYTES) {
            throw new \RuntimeException(sprintf('zip reads total %d actual bytes, over the 64 MiB archive cap', $this->actualTotal));
        }
        return $data;
    }

    /** The structure pass: container, OPF, spine, cover. This skeleton ships
     *  only the first gate every EPUB must pass: without
     *  META-INF/container.xml the file is not an EPUB at all. */
    private function readStructure(): array
    {
        if (!in_array('META-INF/container.xml', array_column($this->stats, 'name'), true)) {
            throw new \RuntimeException('not an EPUB: META-INF/container.xml is missing');
        }
        return ['stats' => $this->stats];
    }
}
