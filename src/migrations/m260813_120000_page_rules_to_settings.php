<?php

namespace widewebpro\aiagent\migrations;

use Craft;
use craft\db\Migration;

class m260813_120000_page_rules_to_settings extends Migration
{
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $schemaVersion = $projectConfig->get('plugins.ai-agent.schemaVersion', true);
        if (version_compare($schemaVersion, '1.1.0', '<') && $this->db->tableExists('{{%aiagent_page_rules}}')) {
            $rules = array_map(
                fn(array $row) => ['pattern' => $row['pattern'], 'ruleType' => $row['ruleType']],
                (new \yii\db\Query())
                    ->select(['pattern', 'ruleType'])
                    ->from('{{%aiagent_page_rules}}')
                    ->orderBy(['sortOrder' => SORT_ASC])
                    ->all($this->db)
            );

            $projectConfig->set('plugins.ai-agent.settings.pageRules', $rules);
        }

        $this->dropTableIfExists('{{%aiagent_page_rules}}');

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260813_120000_page_rules_to_settings cannot be reverted.\n";
        return false;
    }
}
