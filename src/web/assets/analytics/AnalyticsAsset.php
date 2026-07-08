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

        // Chart.js is vendored locally (v4.4.7) rather than loaded from a CDN:
        // the dashboard renders in the authenticated control panel, so a
        // third-party script here would run with full CP privileges.
        $this->js = [
            'js/chart.umd.min.js',
            'js/dashboard.js',
        ];

        $this->css = [
            'css/dashboard.css',
        ];

        parent::init();
    }
}
