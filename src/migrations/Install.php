<?php

declare(strict_types=1);

namespace johnfmorton\llmready\migrations;

use Craft;
use craft\db\Migration;
use johnfmorton\llmready\records\SectionSettingRecord;

/**
 * LLM Ready Install Migration
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();

        Craft::$app->db->schema->refresh();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(SectionSettingRecord::tableName());

        return true;
    }

    private function createTables(): void
    {
        if ($this->db->schema->getTableSchema(SectionSettingRecord::tableName()) === null) {
            $this->createTable(SectionSettingRecord::tableName(), [
                'id' => $this->primaryKey(),
                'sectionId' => $this->integer()->notNull(),
                'siteId' => $this->integer()->notNull(),
                'enabled' => $this->boolean()->notNull()->defaultValue(true),
                'llmTemplate' => $this->string(500)->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
        }
    }

    private function createIndexes(): void
    {
        $this->createIndex(
            null,
            SectionSettingRecord::tableName(),
            ['sectionId', 'siteId'],
            true,
        );

        $this->addForeignKey(
            null,
            SectionSettingRecord::tableName(),
            ['sectionId'],
            '{{%sections}}',
            ['id'],
            'CASCADE',
        );

        $this->addForeignKey(
            null,
            SectionSettingRecord::tableName(),
            ['siteId'],
            '{{%sites}}',
            ['id'],
            'CASCADE',
        );
    }
}
