<?php

declare(strict_types=1);

namespace johnfmorton\llmready\records;

use craft\db\ActiveRecord;

/**
 * Section Setting Record
 *
 * @property int $id
 * @property int $sectionId
 * @property int $siteId
 * @property bool $enabled
 * @property string|null $llmTemplate
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SectionSettingRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%llmready_section_settings}}';
    }
}
