<?php // tests/UsersEmailIndexTest.php
namespace App\Tests;
use Kip\Database;
use Kip\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

/** Migration 063: the lookups that match an email ignoring case use an index
 *  instead of reading every member, and the index never refuses two rows that
 *  differ only by case (an older archive may already hold such a pair). */
final class UsersEmailIndexTest extends TestCase
{
    private function db(): Database
    {
        $db = new Database('sqlite::memory:');
        (new Migrator($db, \App\Tests\Support\AppLayout::migrations()))->migrate();
        return $db;
    }

    public function test_both_case_insensitive_lookups_use_the_index(): void
    {
        $db = $this->db();
        foreach (['lower(email) = lower(?)', 'lower(email) = ?'] as $where) {
            $plan = implode(' ', array_column($db->all('EXPLAIN QUERY PLAN SELECT id FROM users WHERE ' . $where, ['x@e.test']), 'detail'));
            $this->assertStringContainsString('idx_users_lower_email', $plan, $where);
        }
    }

    public function test_the_index_is_not_unique_so_an_upgrade_with_case_variant_rows_still_works(): void
    {
        $db = $this->db();
        $ins = 'INSERT INTO users (email, password_hash, penname) VALUES (?, ?, ?)';
        $db->query($ins, ['Same@e.test', 'x', 'one']);
        $db->query($ins, ['same@e.test', 'x', 'two']);
        $this->assertSame(2, (int) $db->one('SELECT COUNT(*) c FROM users WHERE lower(email) = ?', ['same@e.test'])['c']);
    }
}
