<?php // app/migrations/070_create_storage_usage.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The upload-bytes counter (App\StorageQuota, config
        // 'storage_cap_bytes', 0 = off). One row (id = 1), seeded lazily from
        // a walk of the uploads directory and drift-corrected on a refusal.
        // It lives in data.sqlite on purpose, NOT cache.sqlite:
        // `kip cache:clear` wipes the cache database on every deploy, and a
        // counter that vanishes is not a counter.
        $db->query('CREATE TABLE IF NOT EXISTS storage_usage (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            used INTEGER NOT NULL,
            reconciled_at INTEGER NOT NULL
        )');
    }
    public function down(Kip\Database $db): void { $db->query('DROP TABLE IF EXISTS storage_usage'); }
};
