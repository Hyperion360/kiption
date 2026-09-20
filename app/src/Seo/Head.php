<?php // app/src/Seo/Head.php
namespace App\Seo;

final class Head
{
    private function __construct(
        public readonly string $siteName,
        public string $ogImage, // mutated by withOgImage(): readonly props cannot be written after clone on PHP 8.4
        private string $baseUrl,
        public bool $noindex, // mutated by withNoindex(): readonly props cannot be written after clone on PHP 8.4
        private ?string $titleText,
        private ?string $descriptionText,
        private string $canonicalPath,
        private ?string $articlePublished,
        private ?string $articleModified,
        private array $jsonLdData,
    ) {}

    public static function make(string $siteName, string $ogImage = '', string $baseUrl = ''): self
    {
        return new self($siteName, $ogImage, $baseUrl, false, null, null, '/', null, null, []);
    }

    public function withTitle(?string $title): self
    {
        $c = clone $this; $c->titleText = $title; return $c;
    }
    public function withDescription(?string $description): self
    {
        $c = clone $this; $c->descriptionText = $description; return $c;
    }
    public function withCanonical(string $path): self
    {
        $c = clone $this; $c->canonicalPath = $path; return $c;
    }
    public function withArticle(string $published, string $modified): self
    {
        $c = clone $this; $c->articlePublished = $published; $c->articleModified = $modified; return $c;
    }
    public function withJsonLd(array $data): self
    {
        $c = clone $this; $c->jsonLdData = $data; return $c;
    }
    public function withNoindex(): self
    {
        $c = clone $this; $c->noindex = true; return $c;
    }
    /** Per-page og:image override (a member's avatar on their profile). */
    public function withOgImage(string $path): self
    {
        $c = clone $this; $c->ogImage = $path; return $c;
    }

    public function title(): string
    {
        return $this->titleText === null || $this->titleText === $this->siteName
            ? $this->siteName
            : $this->titleText . ' - ' . $this->siteName;
    }

    public function description(): string
    {
        $d = trim((string) $this->descriptionText);
        if ($d === '') return 'A self-hosted fiction archive.';
        // The budget is 160 characters, never bytes: a byte cut splits multibyte
        // sequences, ships invalid UTF-8, and htmlspecialchars then blanks the
        // whole content attribute. PCRE /u counts characters and cannot split one.
        // On non-UTF-8 input the match fails and the string passes through as-is.
        if (preg_match('/^.{161,}$/us', $d) === 1) {
            preg_match('/^.{160}/us', $d, $m);
            $cut = $m[0];
            $space = strrpos($cut, ' ');
            return $space === false ? rtrim($cut) : rtrim(substr($cut, 0, $space));
        }
        return $d;
    }

    public function canonical(): string
    {
        return $this->baseUrl . $this->canonicalPath; // absolute: og:url and JSON-LD urls require it
    }

    public function url(string $path): string
    {
        return $this->baseUrl . $path;
    }

    /** @return list<array{name: string, content: string}> */
    public function metaTags(): array
    {
        $tags = [['name' => 'description', 'content' => $this->description()]];
        if ($this->noindex) {
            $tags[] = ['name' => 'robots', 'content' => 'noindex'];
        }
        return $tags;
    }

    /** @return list<array{property: string, content: string}> */
    public function ogTags(): array
    {
        $tags = [
            ['property' => 'og:site_name', 'content' => $this->siteName],
            ['property' => 'og:title', 'content' => $this->title()],
            ['property' => 'og:type', 'content' => $this->articlePublished !== null ? 'article' : 'website'],
            ['property' => 'og:url', 'content' => $this->canonical()],
            ['property' => 'og:description', 'content' => $this->description()],
        ];
        if ($this->articlePublished !== null) {
            $tags[] = ['property' => 'article:published_time', 'content' => $this->articlePublished];
            $tags[] = ['property' => 'article:modified_time', 'content' => (string) $this->articleModified];
        }
        if ($this->ogImage !== '') {
            $tags[] = ['property' => 'og:image', 'content' => $this->ogImage];
        }
        return $tags;
    }

    /** @return list<array{name: string, content: string}> */
    public function twitterTags(): array
    {
        return [['name' => 'twitter:card', 'content' => $this->ogImage !== '' ? 'summary_large_image' : 'summary']];
    }

    public function jsonLd(): string
    {
        return $this->jsonLdData === [] ? '' : json_encode(
            $this->jsonLdData,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        );
    }
}
