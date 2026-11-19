<?php
namespace App;

use App\Repositories\AuthoringRepository as Repo;

/** The story-answer view variables, built once for the story form and the
 *  editor popover so the two can never disagree about a field. */
final class StoryFormVars
{
    /** @param array[] $rows formData-shaped rows (the repository's, or rebuilt from the draft story's JSON)
     *  @param array $selectedCats saved category ids; $saved the saved field values; $draft the posted retry, if any
     *  @return array<string, mixed> */
    public static function build(array $rows, array $selectedCats, array $saved, ?array $draft): array
    {
        $ratings = [];
        foreach ($rows as $r) {
            if ($r['k'] === 'r') $ratings[] = $r;
        }
        [$tagGroups, $selectedTags] = Repo::tagGroups($rows);
        [$characterGroups, $selectedCharacters] = Repo::characterGroups($rows);
        return [
            'values' => $draft['fields'] ?? $saved,
            'ratingInvalid' => $draft !== null && !in_array((int) ($draft['fields']['rating_id'] ?? 0), array_map('intval', array_column($ratings, 'a')), true),
            'draftSuggest' => $draft['suggest'] ?? [],
            'draftWithdraw' => $draft['withdraw'] ?? [],
            'passwordDiscarded' => $draft['passwordDiscarded'] ?? false,
            'selectedCategories' => $draft['categories'] ?? $selectedCats,
            'categories' => Repo::visibleCategories($rows, $selectedCats),
            'ratings' => $ratings,
            'tagGroups' => $tagGroups,
            'selectedTags' => $draft['tags'] ?? $selectedTags,
            'suggestions' => Repo::suggestionGroups($rows),
            'tagPolicy' => Settings::get('tag_policy'),
            'characterGroups' => $characterGroups,
            'selectedCharacters' => $draft['characters'] ?? $selectedCharacters,
        ];
    }
}
