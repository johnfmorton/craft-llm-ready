<?php

declare(strict_types=1);

namespace johnfmorton\llmready\utilities;

use Craft;
use craft\base\Utility;
use johnfmorton\llmready\LlmReady;

/**
 * Surfaces the shared-cache detection summary outside plugin settings.
 *
 * This matters most in production: `allowAdminChanges` is typically off
 * there, which hides the Settings section entirely — but utilities remain
 * available. Opening this utility in production is what records that
 * environment's result, so the settings page in dev can then show it.
 */
class CacheCheckUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('llm-ready', 'LLM Ready Cache Check');
    }

    public static function id(): string
    {
        return 'llm-ready-cache-check';
    }

    public static function icon(): ?string
    {
        return 'cloud';
    }

    public static function contentHtml(): string
    {
        $cacheCheck = LlmReady::getInstance()->cacheDetectionService->getCheckData();

        return Craft::$app->getView()->renderTemplate('llm-ready/utilities/cache-check', [
            'cacheCheck' => $cacheCheck,
        ]);
    }
}
