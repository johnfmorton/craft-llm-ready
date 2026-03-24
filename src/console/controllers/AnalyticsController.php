<?php

declare(strict_types=1);

namespace johnfmorton\llmready\console\controllers;

use craft\console\Controller;
use johnfmorton\llmready\LlmReady;
use yii\console\ExitCode;

/**
 * Analytics maintenance commands
 */
class AnalyticsController extends Controller
{
    /**
     * Purge analytics data older than the configured retention period
     */
    public function actionPurge(): int
    {
        $plugin = LlmReady::getInstance();
        $settings = $plugin->getSettings();

        $this->stdout("Purging analytics data older than {$settings->analyticsRetentionDays} days...\n");

        $deleted = $plugin->analyticsService->purgeOldData($settings->analyticsRetentionDays);

        $this->stdout("Deleted {$deleted} records.\n");

        return ExitCode::OK;
    }
}
