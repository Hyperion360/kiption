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

        // Author profile fields the owner defines (App\ProfileFields): a label,
        // a type, and a position for ordering. Members' answers sit in
        // profile_field_values and go away with the field or the member.
        $db->query('CREATE TABLE profile_fields (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            type TEXT NOT NULL CHECK (type IN (\'text\', \'url\', \'yesno\')),
            position INTEGER NOT NULL DEFAULT 0
        )');
        $db->query('CREATE TABLE profile_field_values (
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            field_id INTEGER NOT NULL REFERENCES profile_fields(id) ON DELETE CASCADE,
            value TEXT NOT NULL,
            PRIMARY KEY (user_id, field_id)
        )');
        $db->query('CREATE INDEX idx_profile_field_values_field ON profile_field_values (field_id)');
    }
    public function down(Kip\Database $db): void
    {
        $db->query('DROP TABLE profile_field_values');
        $db->query('DROP TABLE profile_fields');
        $db->query('DROP TABLE signup_check');
    }
};
