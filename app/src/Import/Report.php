<?php // app/src/Import/Report.php
namespace App\Import;

final class Report
{
    public int $substitutions = 0;
    /** @var array<string, int> */
    public array $tableCounts = [];
    /** @var array<string, int> */
    public array $rejects = [];
    /** @var array<string, int> */
    public array $dropped = [];
    public int $missingStoryFiles = 0;
    public int $unresolvableTokens = 0;
    public int $multiRatingStories = 0;
    public int $passwordLegacy = 0;
    public int $responseBlocksExtracted = 0;
    public int $responseMisses = 0;
    public int $alreadyMapped = 0;
    /** @var string[] */
    public array $samples = [];

    public function table(string $t, int $n): void { $this->tableCounts[$t] = $n; }
    public function reject(string $reason): void { $this->rejects[$reason] = ($this->rejects[$reason] ?? 0) + 1; }
    public function drop(string $feature, int $n = 1): void { $this->dropped[$feature] = ($this->dropped[$feature] ?? 0) + $n; }
    public function sample(string $s): void { if (count($this->samples) < 20) $this->samples[] = $s; }

    public function render(): string
    {
        $lines = ["Kiption import report", str_repeat('=', 22), ''];
        foreach ($this->tableCounts as $t => $n) { $lines[] = sprintf('%-28s %d', $t, $n); }
        $lines[] = '';
        $lines[] = "substituted chars: {$this->substitutions}";
        $lines[] = "missing story files: {$this->missingStoryFiles}";
        $lines[] = "unresolvable tokens: {$this->unresolvableTokens}";
        $lines[] = "multi-rating stories (first won): {$this->multiRatingStories}";
        $lines[] = "legacy password hashes: {$this->passwordLegacy}";
        $lines[] = "response blocks extracted: {$this->responseBlocksExtracted} (misses: {$this->responseMisses})";
        $lines[] = "already mapped (skipped): {$this->alreadyMapped}";
        foreach ($this->rejects as $why => $n) { $lines[] = "REJECT $why: $n"; }
        foreach ($this->dropped as $what => $n) { $lines[] = "dropped $what: $n"; }
        if ($this->samples !== []) {
            $lines[] = '';
            $lines[] = 'samples (spot-check these):';
            foreach ($this->samples as $i => $s) { $lines[] = sprintf('  %2d. %s', $i + 1, mb_substr($s, 0, 100)); }
        }
        $lines[] = '';
        $lines[] = 'Treat this bundle as a password file: it contained emails and legacy hashes.';
        return implode("\n", $lines) . "\n";
    }
}
