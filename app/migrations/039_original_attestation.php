<?php // app/migrations/039_original_attestation.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The rights attestation: 1 means the author states the work is
        // original and they hold all rights. It gates support-link display
        // (Task 4) and, later, paywall enablement (launch plan appendix).
        // eFiction imports default to 0 (fanwork archives are the norm);
        // operators flip originals by edit. No index: read surfaces scan
        // by id and the paywall's later queries key on the story row.
        $db->query('ALTER TABLE stories ADD COLUMN is_original INTEGER NOT NULL DEFAULT 0');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('ALTER TABLE stories DROP COLUMN is_original');
    }
};
