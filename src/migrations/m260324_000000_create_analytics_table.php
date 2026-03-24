<?php

declare(strict_types=1);

namespace johnfmorton\llmready\migrations;

use craft\db\Migration;

/**
 * Create analytics table
 */
class m260324_000000_create_analytics_table extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->schema->getTableSchema('{{%llmready_analytics}}') !== null) {
            return true;
        }

        $this->createTable('{{%llmready_analytics}}', [
            'id' => $this->primaryKey(),
            'siteId' => $this->integer()->notNull(),
            'entryId' => $this->integer()->null(),
            'requestType' => $this->string(20)->notNull(),
            'botName' => $this->string(100)->notNull(),
            'requestPath' => $this->string(500)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
        ]);

        $this->createIndex(null, '{{%llmready_analytics}}', ['dateCreated']);
        $this->createIndex(null, '{{%llmready_analytics}}', ['siteId', 'dateCreated']);
        $this->createIndex(null, '{{%llmready_analytics}}', ['botName']);
        $this->createIndex(null, '{{%llmready_analytics}}', ['entryId']);

        $this->addForeignKey(null, '{{%llmready_analytics}}', ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%llmready_analytics}}', ['entryId'], '{{%elements}}', ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%llmready_analytics}}');

        return true;
    }
}
