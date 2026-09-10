<?php

declare(strict_types=1);

namespace johnfmorton\llmready\web\assets\webmcp;

use craft\web\AssetBundle;

/**
 * Asset bundle for the WebMCP tool bootstrap (Phase 0 prototype)
 */
class WebMcpAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__;

        $this->js = [
            'js/webmcp.js',
        ];

        // The script only reacts to an agent calling a tool, so it never
        // needs to block parsing.
        $this->jsOptions = [
            'defer' => true,
        ];

        parent::init();
    }
}
