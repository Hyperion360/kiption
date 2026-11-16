<?php
declare(strict_types=1);

// Original synthetic titles. Keys are identities, never database auto-increment IDs.
$stories = [
    'wonderland' => ['title' => 'Wonderland at Midnight', 'tags' => ['Portal Fantasy', 'Found Family'], 'category' => 'fantasy', 'completed' => 1, 'language' => 'en', 'words' => 12000],
    'winter' => ['title' => 'Winter at the Harbor', 'tags' => ['Coastal Mystery'], 'category' => 'mystery', 'completed' => 0, 'language' => 'en', 'words' => 9000],
    'river' => ['title' => 'Rivers Remember', 'tags' => ['Second Chances'], 'category' => 'romance', 'completed' => 1, 'language' => 'fr', 'words' => 50000],
    'dragon' => ['title' => 'The Dragon Cartographer', 'tags' => ['Maps', 'Dragon Riders'], 'category' => 'fantasy', 'completed' => 0, 'language' => 'en', 'words' => 75000],
    'garden' => ['title' => 'The Glass Garden', 'tags' => ['Botanical Fantasy'], 'category' => 'fantasy', 'completed' => 1, 'language' => 'de', 'words' => 10000],
    'russian' => ['title' => 'Привет из леса', 'tags' => ['Сказка'], 'category' => 'fantasy', 'completed' => 0, 'language' => 'ru', 'words' => 4000],
    'accent' => ['title' => 'Café Beneath the Stars', 'tags' => ['Slow Burn'], 'category' => 'romance', 'completed' => 1, 'language' => 'fr', 'words' => 20000],
    'harbor' => ['title' => 'Harbor Letters', 'tags' => ['Found Family', 'Slow Burn'], 'category' => 'romance', 'completed' => 0, 'language' => 'en', 'words' => 6000],
];
$queries = [];
foreach ($stories as $key => $story) {
    $queries[] = ['q' => $story['title'], 'first' => $key, 'kind' => 'exact'];
}
$queries = array_merge($queries, [
    ['q' => 'wonderland', 'first' => 'wonderland', 'kind' => 'exact'],
    ['q' => 'wonde', 'firstAny' => ['wonderland', 'typo-exact'], 'kind' => 'prefix'],
    ['q' => 'wonderlend', 'first' => 'typo-exact', 'relevant' => 'wonderland', 'topFive' => ['wonderland'], 'kind' => 'substitution', 'oneEdit' => true],
    ['q' => 'wonderlend midnigth', 'first' => 'wonderland', 'kind' => 'multiword'],
    ['q' => 'winter harbor', 'first' => 'winter', 'kind' => 'all-tokens'],
    ['q' => 'rievrs', 'first' => 'river', 'kind' => 'transposition', 'oneEdit' => true],
    ['q' => 'riverss', 'first' => 'river', 'kind' => 'insertion', 'oneEdit' => true],
    ['q' => 'cartogropher', 'first' => 'dragon', 'kind' => 'substitution', 'oneEdit' => true],
    ['q' => 'dragn riders', 'only' => ['dragon'], 'relevant' => 'dragon', 'kind' => 'tag', 'oneEdit' => true],
    ['q' => 'botanial', 'only' => ['garden'], 'relevant' => 'garden', 'kind' => 'tag-deletion', 'oneEdit' => true],
    ['q' => 'превет', 'first' => 'russian', 'kind' => 'unicode', 'oneEdit' => true],
    ['q' => 'café', 'first' => 'accent', 'kind' => 'exact'],
    ['q' => 'CAFÉ', 'first' => 'accent', 'kind' => 'exact'],
    ['q' => 'ПРИВЕТ', 'first' => 'russian', 'kind' => 'exact'],
    ['q' => 'found family', 'only' => ['wonderland', 'harbor'], 'kind' => 'tag'],
    ['q' => 'slowburn', 'only' => ['accent', 'harbor'], 'kind' => 'alias'],
    ['q' => 'slowbrun', 'only' => ['accent', 'harbor'], 'kind' => 'alias-typo'],
    ['q' => 'slow burn', 'only' => ['accent', 'harbor'], 'kind' => 'tag'],
    ['q' => '" OR * NOT', 'none' => true, 'kind' => 'punctuation'],
    ['q' => 'zzqvwxplmn', 'none' => true, 'kind' => 'nonsense'],
    ['q' => 'winter cartographer', 'none' => true, 'kind' => 'cross-token'],
    ['q' => 'midnight remember', 'none' => true, 'kind' => 'cross-token'],
    ['q' => 'wonderland', 'filters' => ['category' => ['mystery']], 'none' => true, 'kind' => 'category-negative'],
    ['q' => 'found family', 'filters' => ['category' => ['fantasy', 'mystery']], 'only' => ['wonderland'], 'kind' => 'category-or'],
    ['q' => 'found family', 'filters' => ['completed' => true], 'only' => ['wonderland'], 'kind' => 'status'],
    ['q' => 'slowburn', 'filters' => ['completed' => false], 'only' => ['harbor'], 'kind' => 'status'],
    ['q' => 'slowburn', 'filters' => ['language' => 'fr'], 'only' => ['accent'], 'kind' => 'language'],
    ['q' => 'slowburn', 'filters' => ['wmin' => 20000, 'wmax' => 20000], 'only' => ['accent'], 'kind' => 'word-boundary'],
    ['q' => 'found family', 'filters' => ['tags' => 'Portal Fantasy'], 'only' => ['wonderland'], 'kind' => 'include'],
    ['q' => 'found family', 'filters' => ['xtags' => 'Portal Fantasy'], 'only' => ['harbor'], 'kind' => 'exclude'],
    ['q' => 'found family', 'filters' => ['tags' => 'Found Family', 'xtags' => 'Slow Burn'], 'only' => ['wonderland'], 'kind' => 'include-exclude'],
    ['q' => '', 'filters' => ['tags' => 'Slowburn', 'language' => 'fr'], 'only' => ['accent'], 'kind' => 'alias-filter'],
    ['q' => '', 'filters' => ['tags' => 'Maps', 'category' => ['fantasy'], 'completed' => false, 'wmin' => 75000], 'only' => ['dragon'], 'kind' => 'filter-combination'],
    ['q' => 'zzqvwxplmn', 'filters' => ['category' => ['fantasy']], 'none' => true, 'kind' => 'filtered-nonsense'],
]);
// Exhaust every edit position, including both boundaries and repeated letters.
$word = 'wonderland';
for ($i = 0; $i < strlen($word); $i++) {
    $queries[] = ['q' => substr($word, 0, $i) . substr($word, $i + 1), 'first' => 'wonderland', 'kind' => 'deletion', 'oneEdit' => true];
    $queries[] = ['q' => substr($word, 0, $i) . 'x' . substr($word, $i + 1), 'first' => 'wonderland', 'kind' => 'substitution', 'oneEdit' => true];
    $queries[] = ['q' => substr($word, 0, $i) . $word[$i] . substr($word, $i), 'first' => 'wonderland', 'kind' => 'repeat', 'oneEdit' => true];
    if ($i + 1 < strlen($word)) {
        $queries[] = ['q' => substr($word, 0, $i) . $word[$i + 1] . $word[$i] . substr($word, $i + 2), 'first' => 'wonderland', 'kind' => 'transposition', 'oneEdit' => true];
    }
}
for ($i = 0; $i <= strlen($word); $i++) {
    $queries[] = ['q' => substr($word, 0, $i) . 'x' . substr($word, $i), 'first' => 'wonderland', 'kind' => 'insertion', 'oneEdit' => true];
}
return ['stories' => $stories, 'aliases' => ['Slowburn' => 'Slow Burn'], 'queries' => $queries];
