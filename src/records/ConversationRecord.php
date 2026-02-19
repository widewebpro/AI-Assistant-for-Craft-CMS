<?php

namespace widewebpro\aiagent\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $sessionId
 * @property string|null $pageUrl
 * @property string|null $ipAddress
 * @property string $status
 * @property array|null $metadata
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class ConversationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%aiagent_conversations}}';
    }

    public function getMessages(): \yii\db\ActiveQuery
    {
        return $this->hasMany(MessageRecord::class, ['conversationId' => 'id'])
            ->orderBy(['dateCreated' => SORT_ASC]);
    }
}
