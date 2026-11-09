<?php

declare(strict_types=1);

namespace johnfmorton\llmready\migrations;

use craft\db\Migration;
use johnfmorton\llmready\records\SectionSettingRecord;

/**
 * Add the per-section Label column.
 *
 * Project config (`llm-ready.sectionSettings.{sectionUid}.{siteUid}.label`)
 * is the source of truth; this column mirrors it like `enabled` and
 * `llmTemplate`. Settings saved before 1.12.0 have no label, so there is
 * nothing to backfill.
 */
class m261109_000000_add_section_label extends Migration
{
    public function safeUp(): bool
    {
        $table = SectionSettingRecord::tableName();

        if (!$this->db->columnExists($table, 'label')) {
            $this->addColumn($table, 'label', $this->string(255)->null()->after('llmTemplate'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = SectionSettingRecord::tableName();

        if ($this->db->columnExists($table, 'label')) {
            $this->dropColumn($table, 'label');
        }

        return true;
    }
}
