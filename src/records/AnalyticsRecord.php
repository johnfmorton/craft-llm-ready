<?php

declare(strict_types=1);

namespace johnfmorton\llmready\records;

use craft\db\ActiveRecord;

/**
 * Analytics request log record
 *
 * @property int $id
 * @property int $siteId
 * @property int|null $entryId
 * @property string $requestType
 * @property string $botName
 * @property string $requestPath
 * @property string $dateCreated
 */
class AnalyticsRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%llmready_analytics}}';
    }
}
