<?php // app/src/Export/Epub.php
namespace App\Export;

/** EPUB 3 assembly over ZipWriter: the mimetype entry FIRST and stored (the
 *  OCF rule; every entry here is stored, which satisfies it), container.xml,
 *  content.opf (the dc metadata, manifest, and spine), nav.xhtml, a minimal
 *  embedded stylesheet, ch{n}.xhtml per validated chapter in position
 *  order, and the cover image bytes when the caller found them.
 *
 *  Chapter bodies are Markdown::render output PATCHED for XHTML: the
 *  renderer emits HTML5 void tags (<hr>, <img ...>) and unclosed void tags
 *  ship invalid EPUBs (review finding 3), so hr/img close HERE only. The
 *  HTML export and every site view keep the HTML5 forms untouched.
 *
 *  The story array is a wholeWork row; the controller additionally offers
 *  cover_data (raw bytes), cover_type (a MIME type), and cover_ext for the
 *  cover, all optional and absent when the story has no readable cover. A
 *  full EPUB validator integration is out of scope (recorded): the
 *  structure tests plus a well-formedness spot check carry the risk. */
final class Epub
{
    public function build(array $story, array $chapters, string $baseUrl): string
    {
        $title = (string) $story['title'];
        $creator = (string) $story['penname'];
        $language = preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', (string) ($story['language'] ?? '')) === 1
            ? (string) $story['language'] : 'en';
        $url = rtrim($baseUrl, '/') . '/story/view/' . (string) $story['slug'];

        $zip = new ZipWriter();
        // The OCF rule: mimetype is the first entry, uncompressed, exactly
        // the media type string plus its newline (pinned by the test).
        $zip->add('mimetype', "application/epub+zip\n");
        $zip->add('META-INF/container.xml', self::containerXml());
        $cover = isset($story['cover_data'], $story['cover_type'], $story['cover_ext'])
            && is_string($story['cover_data']) && $story['cover_data'] !== '' ? $story : null;
        $zip->add('OEBPS/content.opf', $this->opf($title, $creator, $language, $url, $chapters, $cover !== null
            ? [(string) $story['cover_ext'], (string) $story['cover_type']] : null));
        $zip->add('OEBPS/nav.xhtml', $this->nav($title, $chapters));
        $zip->add('OEBPS/style.css', self::stylesheet());
        if ($cover !== null) {
            $zip->add('OEBPS/cover.' . (string) $cover['cover_ext'], (string) $cover['cover_data']);
        }
        foreach ($chapters as $c) {
            $zip->add('OEBPS/ch' . (int) $c['position'] . '.xhtml', self::chapter($c));
        }
        return $zip->finish();
    }

    private static function containerXml(): string
    {
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">' . "\n"
            . " <rootfiles>\n"
            . '  <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>' . "\n"
            . " </rootfiles>\n"
            . "</container>\n";
    }

    /** @param array{position:int,title:string,content:string,word_count:int}[] $chapters
     *  @param array{0:string,1:string}|null $cover [extension, MIME type] */
    private function opf(string $title, string $creator, string $language, string $url, array $chapters, ?array $cover): string
    {
        $manifest = '  <item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>' . "\n"
            . '  <item id="css" href="style.css" media-type="text/css"/>' . "\n";
        if ($cover !== null) {
            $manifest .= '  <item id="cover-image" href="cover.' . $cover[0] . '" media-type="' . $cover[1]
                . '" properties="cover-image"/>' . "\n";
        }
        $spine = '';
        foreach ($chapters as $c) {
            $n = (int) $c['position'];
            $manifest .= '  <item id="ch' . $n . '" href="ch' . $n . '.xhtml" media-type="application/xhtml+xml"/>' . "\n";
            $spine .= '  <itemref idref="ch' . $n . '"/>' . "\n";
        }
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id">' . "\n"
            . ' <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n"
            . '  <dc:identifier id="pub-id">' . self::x($url) . '</dc:identifier>' . "\n"
            . '  <dc:title>' . self::x($title) . '</dc:title>' . "\n"
            . '  <dc:creator>' . self::x($creator) . '</dc:creator>' . "\n"
            . '  <dc:language>' . self::x($language) . '</dc:language>' . "\n"
            . '  <meta property="dcterms:modified">' . gmdate('Y-m-d\TH:i:s\Z') . '</meta>' . "\n"
            . " </metadata>\n <manifest>\n" . $manifest . " </manifest>\n <spine>\n" . $spine . " </spine>\n</package>\n";
    }

    /** @param array{position:int,title:string,content:string,word_count:int}[] $chapters */
    private function nav(string $title, array $chapters): string
    {
        $items = '';
        foreach ($chapters as $c) {
            $n = (int) $c['position'];
            $items .= '   <li><a href="ch' . $n . '.xhtml">' . self::x(self::chapterTitle($c, $n)) . '</a></li>' . "\n";
        }
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<!DOCTYPE html>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops">' . "\n"
            . "<head>\n <title>" . self::x($title) . "</title>\n"
            . ' <link rel="stylesheet" type="text/css" href="style.css"/>' . "\n"
            . "</head>\n<body>\n<nav epub:type=\"toc\" id=\"toc\">\n <h1>" . self::x($title) . "</h1>\n <ol>\n"
            . $items
            . " </ol>\n</nav>\n</body>\n</html>\n";
    }

    /** @param array{position:int,title:string,content:string,word_count:int} $c */
    private static function chapter(array $c): string
    {
        $n = (int) $c['position'];
        $title = self::chapterTitle($c, $n);
        // Markdown at rest; the corpus guarantee that raw HTML cannot be
        // stored is what makes this wrap safe. The void-tag patch is
        // Epub-only (finding 3): hr and img close for XHTML.
        $body = \App\Markdown::render((string) $c['content']);
        $body = str_replace('<hr>', '<hr/>', $body);
        $body = (string) preg_replace('#<img ([^>]*[^/])>#', '<img $1/>', $body);
        return '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<!DOCTYPE html>' . "\n"
            . '<html xmlns="http://www.w3.org/1999/xhtml">' . "\n"
            . "<head>\n <title>" . self::x($title) . "</title>\n"
            . ' <link rel="stylesheet" type="text/css" href="style.css"/>' . "\n"
            . "</head>\n<body>\n <h1>" . self::x($title) . "</h1>\n"
            . $body
            . "</body>\n</html>\n";
    }

    private static function stylesheet(): string
    {
        return "body { font-family: serif; line-height: 1.5; margin: 0 0.1em; }\n"
            . "h1 { font-size: 1.3em; margin: 1.2em 0 0.4em; }\n"
            . "hr { border: 0; border-top: 1px solid #888; margin: 1em 0; }\n"
            . "blockquote { border-left: 3px solid #888; margin: 0.8em 0; padding-left: 0.8em; color: #333; }\n";
    }

    /** @param array{position:int,title:string,content:string,word_count:int} $c */
    private static function chapterTitle(array $c, int $n): string
    {
        return (string) $c['title'] !== '' ? (string) $c['title'] : 'Chapter ' . $n;
    }

    private static function x(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
