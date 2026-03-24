<?php

declare(strict_types=1);

namespace johnfmorton\llmready\web\assets\analytics;

use craft\web\AssetBundle;

/**
 * Asset bundle for the analytics dashboard
 */
class AnalyticsAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;

        $this->js = [
            'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
            'js/dashboard.js',
        ];

        $this->css = [
            'css/dashboard.css',
        ];

        parent::init();
    }
}
