<?php // app/Features/Auth/migrations/057_create_signup_check_and_profile_fields.php
return new class extends Kip\Migrations\Migration {
    public function up(Kip\Database $db): void
    {
        // The owner's optional signup question (App\SignupCheck). One row, id 1,
        // made on first save. Off until the owner turns it on.
        $db->query('CREATE TABLE signup_check (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            enabled INTEGER NOT NULL DEFAULT 0,
            question TEXT NOT NULL DEFAULT \'\',
            answer TEXT NOT NULL DEFAULT \'\'
        )');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE signup_check');
    }
};
