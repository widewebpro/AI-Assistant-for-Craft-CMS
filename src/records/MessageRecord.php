<?php

namespace widewebpro\aiassistant\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $conversationId
 * @property string $role
 * @property string|null $content
 * @property array|null $toolCalls
 * @property array|null $toolResults
 * @property int|null $tokensUsed
 * @property string $dateCreated
 */
class MessageRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%aiassistant_messages}}';
    }

    public function getConversation(): \yii\db\ActiveQuery
    {
        return $this->hasOne(ConversationRecord::class, ['id' => 'conversationId']);
    }
}
