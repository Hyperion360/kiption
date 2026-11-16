<?php // app/src/Look/Color.php
declare(strict_types=1);
namespace App\Look;

/** An sRGB color. The only way in from outside is parse(), which accepts
 *  exactly #RRGGBB. Everything the compiler prints comes from hex(), never
 *  from the string the owner typed. */
final class Color
{
    private function __construct(public readonly int $r, public readonly int $g, public readonly int $b) {}

    /** /D so a trailing newline cannot ride along; no other syntax is accepted. */
    public static function parse(string $hex): ?self
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/D', $hex) !== 1) return null;
        return new self((int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2)));
    }

    public function hex(): string { return sprintf('#%02x%02x%02x', $this->r, $this->g, $this->b); }

    public function luminance(): float
    {
        $f = static fn (int $v): float => ($c = $v / 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        return 0.2126 * $f($this->r) + 0.7152 * $f($this->g) + 0.0722 * $f($this->b);
    }

    public static function contrast(self $a, self $b): float
    {
        [$hi, $lo] = $a->luminance() >= $b->luminance() ? [$a, $b] : [$b, $a];
        return ($hi->luminance() + 0.05) / ($lo->luminance() + 0.05);
    }

    /** $weight of $other, the rest of $this. */
    public function mix(self $other, float $weight): self
    {
        $m = static fn (int $x, int $y): int => (int) round($x + ($y - $x) * max(0.0, min(1.0, $weight)));
        return new self($m($this->r, $other->r), $m($this->g, $other->g), $m($this->b, $other->b));
    }

    /** @return array{0: float, 1: float, 2: float} hue 0-360, saturation 0-1, lightness 0-1 */
    public function toHsl(): array
    {
        [$r, $g, $b] = [$this->r / 255, $this->g / 255, $this->b / 255];
        $max = max($r, $g, $b); $min = min($r, $g, $b); $l = ($max + $min) / 2; $d = $max - $min;
        if ($d == 0.0) return [0.0, 0.0, $l];
        $s = $d / (1 - abs(2 * $l - 1));
        $h = match ($max) { $r => fmod(($g - $b) / $d, 6), $g => ($b - $r) / $d + 2, default => ($r - $g) / $d + 4 } * 60;
        return [$h < 0 ? $h + 360 : $h, $s, $l];
    }

    public static function fromHsl(float $h, float $s, float $l): self
    {
        $c = (1 - abs(2 * $l - 1)) * $s; $x = $c * (1 - abs(fmod($h / 60, 2) - 1)); $m = $l - $c / 2;
        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0], $h < 120 => [$x, $c, 0], $h < 180 => [0, $c, $x],
            $h < 240 => [0, $x, $c], $h < 300 => [$x, 0, $c], default => [$c, 0, $x],
        };
        $to = static fn (float|int $v): int => max(0, min(255, (int) round(($v + $m) * 255)));
        return new self($to($r), $to($g), $to($b));
    }

    /** Step lightness (darker or lighter) in 2 percent steps until contrast
     *  with $against reaches $min; returns the best reachable color. */
    public function ensureContrast(self $against, float $min, bool $darker): self
    {
        $c = $this;
        for ($i = 0; $i < 50 && self::contrast($c, $against) < $min; $i++) {
            [$h, $s, $l] = $c->toHsl();
            $l = $darker ? max(0.0, $l - 0.02) : min(1.0, $l + 0.02);
            $c = self::fromHsl($h, $s, $l);
        }
        return $c;
    }

    public static function white(): self { return new self(255, 255, 255); }
    public static function black(): self { return new self(0, 0, 0); }
}
