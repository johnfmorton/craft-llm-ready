<?php

declare(strict_types=1);

namespace johnfmorton\llmready\migrations;

use craft\db\Migration;

/**
 * Create the per-environment cache check table (#33).
 *
 * One row per CRAFT_ENVIRONMENT value, holding the last passive detection
 * result and the last active probe result for that environment. Stored in
 * the DB rather than project config because it is environment-specific
 * state that must never sync between environments.
 */
class m260802_100000_create_cache_checks_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema('{{%llmready_cache_checks}}') !== null) {
            return true;
        }

        $this->createTable('{{%llmready_cache_checks}}', [
            'id' => $this->primaryKey(),
            'environment' => $this->string(64)->notNull(),
            'passiveData' => $this->text()->null(),
            'passiveDate' => $this->dateTime()->null(),
            'probeData' => $this->text()->null(),
            'probeDate' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%llmready_cache_checks}}', ['environment'], true);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%llmready_cache_checks}}');

        return true;
    }
}
