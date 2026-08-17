<?php

namespace widewebpro\aiagent\migrations;

use craft\db\Migration;

class m260817_120000_decode_json_columns extends Migration
{
    private const TARGETS = [
        ['{{%aiagent_conversations}}', 'metadata'],
        ['{{%aiagent_messages}}', 'toolCalls'],
        ['{{%aiagent_messages}}', 'toolResults'],
    ];

    public function safeUp(): bool
    {
        foreach (self::TARGETS as [$table, $column]) {
            for ($pass = 0; $pass < 5; $pass++) {
                $affected = $this->db->createCommand(
                    "UPDATE {$table}
                     SET [[{$column}]] = CAST(JSON_UNQUOTE([[{$column}]]) AS JSON)
                     WHERE [[{$column}]] IS NOT NULL
                       AND JSON_TYPE([[{$column}]]) = 'STRING'
                       AND JSON_VALID(JSON_UNQUOTE([[{$column}]]))"
                )->execute();

                echo "    > {$table}.{$column} pass " . ($pass + 1) . ": {$affected} row(s) unwrapped\n";

                if ($affected === 0) {
                    break;
                }
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m260817_120000_decode_json_columns cannot be reverted (data repair).\n";

        return true;
    }
}
