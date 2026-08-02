<?php

declare(strict_types=1);

namespace johnfmorton\llmready\migrations;

use Craft;
use craft\db\Migration;

/**
 * Pins `enableUserAgentDetection` to true for installs that predate the
 * default flipping to false.
 *
 * The setting used to default to true, so an install that never opened the
 * settings page was relying on that implicit default. Flipping the default
 * would silently switch the behaviour off for them on upgrade. Writing the
 * value explicitly here makes the existing behaviour survive the change; the
 * site owner can then turn it off deliberately.
 *
 * This only affects existing installs. Craft marks every update migration as
 * applied when a plugin is installed fresh, without running it, so a new
 * install keeps the new default of false.
 */
class m260802_000000_preserve_user_agent_detection extends Migration
{
    private const CONFIG_PATH = 'plugins.llm-ready.settings';

    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // Sites with `allowAdminChanges` off deploy project config from
        // files, so there is nothing writable here. They pick the value up
        // from the config that gets deployed with the upgrade.
        if ($projectConfig->readOnly) {
            Craft::warning(
                'LLM Ready: project config is read-only, so enableUserAgentDetection was not pinned. '
                . 'If this site relies on canonical-URL User-Agent detection, set it explicitly in '
                . 'config/llm-ready.php or in the deployed project config.',
                __METHOD__,
            );

            return true;
        }

        // Read the raw stored settings rather than the settings model —
        // the model has any config/llm-ready.php overrides already applied,
        // and baking those into project config would make a file-based
        // value stick in the database.
        $stored = $projectConfig->get(self::CONFIG_PATH);
        $settings = is_array($stored) ? $stored : [];

        // Already set explicitly, either way — leave the owner's choice alone.
        if (array_key_exists('enableUserAgentDetection', $settings)) {
            return true;
        }

        $settings['enableUserAgentDetection'] = true;

        $projectConfig->set(
            self::CONFIG_PATH,
            $settings,
            'Pin enableUserAgentDetection for LLM Ready, preserving pre-upgrade behaviour',
        );

        return true;
    }

    public function safeDown(): bool
    {
        // Nothing to undo — the value written here is a valid setting either
        // way, and removing it would change behaviour rather than restore it.
        return true;
    }
}
