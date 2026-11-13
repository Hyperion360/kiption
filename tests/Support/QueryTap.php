<?php // tests/Support/QueryTap.php
namespace App\Tests\Support;

use Kip\Database;

/** Counts queries at the PDO layer, below Kip\App's own tap.
 *
 *  App::handle() calls Database::onQuery() at the start of every request and
 *  detaches it at the end, so a listener a test set beforehand never fires
 *  (the count silently reads 0). Swapping the Database's PDO handle for a
 *  tracing subclass puts the listener where handle() cannot replace it.
 *  Statements on that handle that are not content reads or writes
 *  (transaction control, savepoints, PRAGMA) are skipped, matching the
 *  onQuery contract. */
final class QueryTap
{
    /** Same signature as Database::onQuery(), but survives App::handle(). */
    public static function on(Database $db, callable $listener): void
    {
        self::tracer($db)->listener = $listener;
    }

    public static function off(Database $db): void
    {
        self::tracer($db)->listener = null;
    }

    private static function tracer(Database $db): TracingPdo
    {
        $prop = new \ReflectionProperty(Database::class, 'pdo');
        $pdo = $prop->getValue($db);
        if ($pdo instanceof TracingPdo) return $pdo;
        $tracing = new TracingPdo($db->dsn(), null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        foreach (['PRAGMA journal_mode = WAL', 'PRAGMA busy_timeout = 5000', 'PRAGMA foreign_keys = ON'] as $p) {
            $tracing->execRaw($p);
        }
        $prop->setValue($db, $tracing);
        return $tracing;
    }
}

final class TracingPdo extends \PDO
{
    /** @var null|callable(string):void */
    public $listener = null;

    public function execRaw(string $sql): int|false { return parent::exec($sql); }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $this->trace($query);
        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->trace($statement);
        return parent::exec($statement);
    }

    private function trace(string $sql): void
    {
        if ($this->listener === null) return;
        if (preg_match('/^\s*(SAVEPOINT|RELEASE|BEGIN|COMMIT|ROLLBACK|PRAGMA)\b/i', $sql)) return;
        ($this->listener)($sql);
    }
}
