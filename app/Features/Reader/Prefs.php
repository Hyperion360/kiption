<?php // app/Features/Reader/Prefs.php
namespace App\Features\Reader;
use Kip\Http\Request;

/** Typography preferences, one compact cookie ("19-serif-regular-indented-
 *  medium-scroll"). Cookie-only by design: the perusertheme DB row covers
 *  theme; text prefs are device-local (YAGNI on server sync). */
final class Prefs
{
    public const COOKIE = 'reader';
    public const SIZES = [16, 17, 18, 19, 20, 21, 22, 23, 24];
    public const TYPEFACES = ['serif', 'sans'];
    public const SPACINGS = ['snug', 'regular', 'airy'];
    public const PARAGRAPHS = ['indented', 'spaced'];
    public const WIDTHS = ['narrow', 'medium', 'wide'];
    public const MODES = ['scroll', 'pages'];

    public function __construct(
        public readonly int $size = 19,
        public readonly string $typeface = 'serif',
        public readonly string $spacing = 'regular',
        public readonly string $paragraphs = 'indented',
        public readonly string $width = 'medium',
        public readonly string $mode = 'scroll',
    ) {}

    /** @param ?Request $request nullable so the layout can call it before the
     *  envelope task has taught every controller to pass request. */
    public static function current(?Request $request): self
    {
        $raw = $request?->cookies[self::COOKIE] ?? '';
        if (substr_count($raw, '-') !== 5) { return new self(); }
        [$size, $typeface, $spacing, $paragraphs, $width, $mode] = explode('-', $raw);
        $size = (int) $size;
        return new self(
            in_array($size, self::SIZES, true) ? $size : 19,
            in_array($typeface, self::TYPEFACES, true) ? $typeface : 'serif',
            in_array($spacing, self::SPACINGS, true) ? $spacing : 'regular',
            in_array($paragraphs, self::PARAGRAPHS, true) ? $paragraphs : 'indented',
            in_array($width, self::WIDTHS, true) ? $width : 'medium',
            in_array($mode, self::MODES, true) ? $mode : 'scroll',
        );
    }

    public function cookieValue(): string
    {
        return implode('-', [$this->size, $this->typeface, $this->spacing, $this->paragraphs, $this->width, $this->mode]);
    }

    /** Reading-column attributes for <html>; empty string when every pref is
     *  default so cookieless cached pages stay byte-stable. */
    public function dataAttrs(): string
    {
        $d = new self();
        $a = '';
        if ($this->size !== $d->size) { $a .= ' data-size="' . $this->size . '"'; }
        if ($this->typeface !== $d->typeface) { $a .= ' data-typeface="' . $this->typeface . '"'; }
        if ($this->spacing !== $d->spacing) { $a .= ' data-spacing="' . $this->spacing . '"'; }
        if ($this->paragraphs !== $d->paragraphs) { $a .= ' data-paragraphs="' . $this->paragraphs . '"'; }
        if ($this->width !== $d->width) { $a .= ' data-width="' . $this->width . '"'; }
        if ($this->mode !== $d->mode) { $a .= ' data-mode="' . $this->mode . '"'; }
        return $a;
    }
}
