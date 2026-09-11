<?php

declare(strict_types=1);

namespace johnfmorton\llmready\records;

use craft\db\ActiveRecord;

/**
 * Per-environment shared-cache detection result
 *
 * One row per `CRAFT_ENVIRONMENT` value. Environment-specific state lives in
 * the DB rather than project config on purpose: a detection result from
 * production must never sync into dev, and vice versa.
 *
 * @property int $id
 * @property string $environment
 * @property string|null $passiveData
 * @property string|null $passiveDate
 * @property string|null $probeData
 * @property string|null $probeDate
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class CacheCheckRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%llmready_cache_checks}}';
    }
}
