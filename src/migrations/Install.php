<?php

declare(strict_types=1);

namespace johnfmorton\llmready\migrations;

use Craft;
use craft\db\Migration;
use johnfmorton\llmready\records\AnalyticsRecord;
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

        // Rebuild DB table from project config (e.g., when installing on a new environment)
        $this->rebuildFromProjectConfig();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(AnalyticsRecord::tableName());
        $this->dropTableIfExists(SectionSettingRecord::tableName());

        // Clean up project config
        Craft::$app->getProjectConfig()->remove(\johnfmorton\llmready\LlmReady::PROJECT_CONFIG_PATH);

        return true;
    }

    private function createTables(): void
    {
        if ($this->db->schema->getTableSchema(AnalyticsRecord::tableName()) === null) {
            $this->createTable(AnalyticsRecord::tableName(), [
                'id' => $this->primaryKey(),
                'siteId' => $this->integer()->notNull(),
                'entryId' => $this->integer()->null(),
                'requestType' => $this->string(20)->notNull(),
                'botName' => $this->string(100)->notNull(),
                'requestPath' => $this->string(500)->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
            ]);
        }

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
        $this->createIndex(null, AnalyticsRecord::tableName(), ['dateCreated']);
        $this->createIndex(null, AnalyticsRecord::tableName(), ['siteId', 'dateCreated']);
        $this->createIndex(null, AnalyticsRecord::tableName(), ['botName']);
        $this->createIndex(null, AnalyticsRecord::tableName(), ['entryId']);

        $this->addForeignKey(null, AnalyticsRecord::tableName(), ['siteId'], '{{%sites}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, AnalyticsRecord::tableName(), ['entryId'], '{{%elements}}', ['id'], 'SET NULL');

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

    /**
     * Rebuild the DB table from project config data
     */
    private function rebuildFromProjectConfig(): void
    {
        $configPath = \johnfmorton\llmready\LlmReady::PROJECT_CONFIG_PATH;
        $sectionSettings = Craft::$app->getProjectConfig()->get($configPath) ?? [];

        $sectionsService = Craft::$app->getEntries();
        $sitesService = Craft::$app->getSites();

        foreach ($sectionSettings as $sectionUid => $siteConfigs) {
            $section = $sectionsService->getSectionByUid($sectionUid);
            if ($section === null) {
                continue;
            }

            foreach ($siteConfigs as $siteUid => $values) {
                /** @var \craft\models\Site|null $site */
                $site = $sitesService->getSiteByUid($siteUid);
                if ($site === null) {
                    continue;
                }

                $record = new SectionSettingRecord();
                $record->sectionId = $section->id;
                $record->siteId = $site->id;
                $record->enabled = $values['enabled'] ?? true;
                $record->llmTemplate = $values['llmTemplate'] ?? null;
                $record->save();
            }
        }
    }
}
