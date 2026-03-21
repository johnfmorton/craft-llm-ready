<?php

declare(strict_types=1);

namespace johnfmorton\llmready\migrations;

use Craft;
use craft\db\Migration;
use johnfmorton\llmready\LlmReady;
use johnfmorton\llmready\records\SectionSettingRecord;

/**
 * Migrates section settings from the database to project config
 */
class m250321_000000_section_settings_to_project_config extends Migration
{
    public function safeUp(): bool
    {
        // Read existing records from the DB and write them to project config
        /** @var SectionSettingRecord[] $records */
        $records = SectionSettingRecord::find()->all();
        $projectConfig = Craft::$app->getProjectConfig();
        $sectionsService = Craft::$app->getEntries();
        $sitesService = Craft::$app->getSites();

        foreach ($records as $record) {
            $section = $sectionsService->getSectionById($record->sectionId);
            $site = $sitesService->getSiteById($record->siteId);

            if ($section === null || $site === null) {
                continue;
            }

            $configPath = LlmReady::PROJECT_CONFIG_PATH . ".{$section->uid}.{$site->uid}";
            $projectConfig->set($configPath, [
                'enabled' => (bool) $record->enabled,
                'llmTemplate' => $record->llmTemplate,
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Remove section settings from project config
        Craft::$app->getProjectConfig()->remove(LlmReady::PROJECT_CONFIG_PATH);

        return true;
    }
}
