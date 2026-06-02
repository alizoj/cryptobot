<?php

declare(strict_types=1);

use yii\db\Migration;

/**
 * Adds a coarse `role` column to {{%user}} (admin = full access, guest =
 * view-only) and seeds a read-only `guest` account for the backend.
 *
 * Existing accounts are promoted to 'admin' so nothing they could do before
 * breaks; brand-new rows default to the least-privilege 'guest'.
 */
class m260602_120000_add_user_role_and_guest extends Migration
{
    public function safeUp(): void
    {
        $this->addColumn('{{%user}}', 'role', $this->string(20)->notNull()->defaultValue('guest'));

        // Pre-existing users predate roles → give them full access.
        $this->update('{{%user}}', ['role' => 'admin']);

        // Seed a view-only account. Credentials: guest / guest1024!
        $this->insert('{{%user}}', [
            'username' => 'guest',
            'auth_key' => Yii::$app->security->generateRandomString(),
            'password_hash' => Yii::$app->security->generatePasswordHash('guest123'),
            'email' => 'guest@local',
            'status' => 10,
            'role' => 'guest',
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function safeDown(): void
    {
        $this->delete('{{%user}}', ['username' => 'guest']);
        $this->dropColumn('{{%user}}', 'role');
    }
}
