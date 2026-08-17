<?php

namespace widewebpro\aiagent\migrations;

use Craft;
use craft\db\Migration;

class m260817_150000_remove_max_messages extends Migration
{
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $schemaVersion = $projectConfig->get('plugins.ai-agent.schemaVersion', true);
        if (version_compare($schemaVersion, '1.4.0', '<')) {
            $projectConfig->remove('plugins.ai-agent.settings.maxMessagesPerConversation');
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260817_150000_remove_max_messages cannot be reverted.\n";
        return false;
    }
}
