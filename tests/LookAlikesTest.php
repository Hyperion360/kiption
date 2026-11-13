<?php
declare(strict_types=1);

namespace App\Tests;

use App\LookAlikes;
use PHPUnit\Framework\TestCase;

final class LookAlikesTest extends TestCase
{
    public function test_names_that_differ_only_by_case_spacing_or_punctuation_are_grouped(): void
    {
        $this->assertSame(
            [['Slow burn', 'slowburn', 'Slow-Burn'], ['Café', 'café']],
            LookAlikes::groups(['Slow burn', 'Cozy', 'slowburn', 'Café', 'Slow-Burn', 'café', 'Grief']));
    }

    public function test_different_names_and_exact_repeats_are_not_reported(): void
    {
        $this->assertSame([], LookAlikes::groups(['Cozy', 'Cozy', 'Grief', '---', '']));
    }
}
