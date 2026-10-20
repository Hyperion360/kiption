<?php // app/migrations/037_legacy_bcrypt.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // One purpose: carry eFiction 3.5.7's bcrypt password hashes across
        // import so those members keep their password; cleared at their first
        // successful login by AuthController::tryLegacyUpgrade, exactly like
        // legacy_md5. Exclusive with legacy_md5 by mapper construction.
        $db->query('ALTER TABLE users ADD COLUMN legacy_bcrypt TEXT');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('ALTER TABLE users DROP COLUMN legacy_bcrypt');
    }
};
