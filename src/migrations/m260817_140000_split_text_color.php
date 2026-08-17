<?php

namespace widewebpro\aiagent\migrations;

use Craft;
use craft\db\Migration;

class m260817_140000_split_text_color extends Migration
{
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $schemaVersion = $projectConfig->get('plugins.ai-agent.schemaVersion', true);
        if (version_compare($schemaVersion, '1.3.0', '<')) {
            $textColor = $projectConfig->get('plugins.ai-agent.settings.textColor', true);
            if (is_string($textColor) && $textColor !== '') {
                $projectConfig->set('plugins.ai-agent.settings.secondaryTextColor', $textColor);
            }
            $projectConfig->remove('plugins.ai-agent.settings.textColor');
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260817_140000_split_text_color cannot be reverted.\n";
        return false;
    }
}
