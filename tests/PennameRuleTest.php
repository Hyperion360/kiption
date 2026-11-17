<?php // tests/PennameRuleTest.php
namespace App\Tests;
use PHPUnit\Framework\Attributes\Medium;

use App\Repositories\UserRepository;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The one pen-name rule, shared by sign-up and rename. */
#[Medium]
final class PennameRuleTest extends TestCase
{
    private string $path = '';
    private Database $db;
    private UserRepository $users;

    protected function setUp(): void
    {
        $this->path = \App\Tests\Support\SchemaTemplate::path('kiption-penrule-');
        $this->db = new Database('sqlite:' . $this->path);
        (new Migrator($this->db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        $this->users = new UserRepository($this->db);
        $this->addMember('a@example.test', 'Taken Name', 'taken-name'); // id 1
    }

    protected function tearDown(): void
    {
        unset($this->db);
        @unlink($this->path); @unlink($this->path . '-wal'); @unlink($this->path . '-shm');
        @unlink(substr($this->path, 0, -7));
    }

    private function addMember(string $email, string $penname, string $slug): int
    {
        $this->db->query("INSERT INTO users (email, password_hash, penname, profile_slug, role) VALUES (?, 'x', ?, ?, 'member')", [$email, $penname, $slug]);
        return (int) $this->db->lastInsertId();
    }

    #[DataProvider('good')]
    public function test_accepts(string $name): void
    {
        $this->assertNull($this->users->pennameError($name));
    }

    public static function good(): array
    {
        return [['Scribble'], ['Clark Kent'], ['lois_lane-2'], ['abc'], ['Jo'], ['Chloé'], ['J.R.'], ["O'Brien"], [str_repeat('a', 40)]];
    }

    #[DataProvider('bad')]
    public function test_refuses(string $name): void
    {
        $this->assertNotNull($this->users->pennameError($name));
    }

    public static function bad(): array
    {
        return [['a'], [str_repeat('a', 41)], [' lead'], ['trail '], ['two  spaces'], ['<b>'], ['taken name'], ['TAKEN NAME']];
    }

    public function test_reasons_are_lang_keys_that_exist(): void
    {
        foreach ([$this->users->pennameError('<b>'), $this->users->pennameError('taken name'), $this->users->pennameError("\u{0405}cribble")] as $key) {
            $this->assertNotSame($key, \App\Lang::t($key), "$key is missing from the language file");
        }
        $this->assertSame('auth.err_penname_format', $this->users->pennameError('<b>'));
        $this->assertSame('auth.err_penname_taken', $this->users->pennameError('taken name'));
    }

    public function test_own_name_is_not_taken_when_renaming_yourself(): void
    {
        $this->assertNull($this->users->pennameError('taken name', 1));
    }

    public function test_resaving_current_nonconforming_name_is_allowed(): void
    {
        $id = $this->addMember('b@example.test', 'x', 'x'); // one character: fails the format rule
        $this->assertNull($this->users->pennameError('x', $id));
        $this->assertNull($this->users->pennameError('X', $id), 'a case-only change');
    }

    public function test_resaving_own_imported_name_is_allowed(): void
    {
        $id = $this->addMember('c@example.test', 'Anna  B!', 'anna-b');
        $this->assertNull($this->users->pennameError('Anna  B!', $id));
    }

    public function test_name_that_slugs_like_another_member_is_refused(): void
    {
        $this->addMember('ck@example.test', 'Clark Kent', 'clark-kent');
        $this->assertNotNull($this->users->pennameError('clark_kent'));
    }

    public function test_numbered_slug_forms_count_as_lookalikes(): void
    {
        $this->addMember('ck2@example.test', 'Clark Kent', 'clark-kent-2');
        $this->assertNotNull($this->users->pennameError('clark_kent'));
    }

    public function test_shorter_name_sharing_a_prefix_is_allowed(): void
    {
        $this->addMember('ck@example.test', 'Clark Kent', 'clark-kent');
        $this->assertNull($this->users->pennameError('Clark'));
    }

    public function test_invalid_utf8_is_refused(): void
    {
        $this->assertNotNull($this->users->pennameError("Cl\xFFark"));
    }

    public function test_mixed_script_lookalike_is_refused(): void
    {
        $this->assertSame('account.penname_mixed_scripts', $this->users->pennameError("\u{0405}cribble"));
    }

    public function test_three_combining_marks_refused(): void
    {
        $this->assertNotNull($this->users->pennameError("a\u{0301}\u{0302}\u{0303}b"));
    }

    public function test_two_non_latin_names_can_coexist(): void
    {
        $this->addMember('iv@example.test', 'Иван', 'member'); // slug falls back to "member"
        $this->assertNull($this->users->pennameError('Мария'));
    }

    public function test_accented_case_variant_is_taken(): void
    {
        $this->addMember('ch@example.test', 'Chloé', \App\Slug::make('Chloé', 'member')); // the real slug is "chlo": Slug::make is ASCII-only
        $this->assertNotNull($this->users->pennameError('CHLOÉ'));
    }
}
