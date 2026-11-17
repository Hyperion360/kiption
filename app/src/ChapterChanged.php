<?php
namespace App;

/** Carries the transaction's saved version without exposing its access hash. */
final class ChapterChanged extends \RuntimeException
{
    private readonly array $savedFields;
    private readonly string $savedRevision;

    public function __construct(array $current)
    {
        parent::__construct('chapter changed');
        $this->savedRevision = ChapterRevision::of($current);
        $this->savedFields = array_intersect_key($current, array_flip([
            'chapter_id', 'title', 'content', 'notes_before', 'notes_after',
            'publish_at', 'recovery_protected',
        ]));
    }

    public function savedFields(): array { return $this->savedFields; }
    public function savedRevision(): string { return $this->savedRevision; }
}
