<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use widewebpro\aiagent\Plugin;
use widewebpro\aiagent\records\ConversationRecord;
use widewebpro\aiagent\records\MessageRecord;

class ChatService extends Component
{
    public function getOrCreateConversation(string $sessionId, string $pageUrl = '', string $ip = ''): ConversationRecord
    {
        $record = ConversationRecord::find()
            ->where(['sessionId' => $sessionId])
            ->one();

        if ($record) {
            return $record;
        }

        $record = new ConversationRecord();
        $record->sessionId = $sessionId;
        $record->pageUrl = $pageUrl ?: null;
        $record->ipAddress = $ip ?: null;
        $record->status = 'active';
        $record->uid = StringHelper::UUID();
        $record->save(false);

        return $record;
    }

    public function addMessage(int $conversationId, string $role, ?string $content, array $extras = []): MessageRecord
    {
        $record = new MessageRecord();
        $record->conversationId = $conversationId;
        $record->role = $role;
        $record->content = $content;
        $record->toolCalls = $extras['toolCalls'] ?? null;
        $record->toolResults = $extras['toolResults'] ?? null;
        $record->tokensUsed = $extras['tokensUsed'] ?? null;
        $record->uid = StringHelper::UUID();
        $record->save(false);

        return $record;
    }

    public function getConversationHistory(int $conversationId, int $limit = 20): array
    {
        $messages = MessageRecord::find()
            ->where(['conversationId' => $conversationId])
            ->andWhere(['in', 'role', ['user', 'assistant']])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit($limit)
            ->all();

        $messages = array_reverse($messages);

        $history = [];
        foreach ($messages as $msg) {
            $history[] = [
                'role' => $msg->role,
                'content' => $msg->content ?? '',
            ];
        }

        return $history;
    }

    public function getMessageCount(int $conversationId): int
    {
        return (int)MessageRecord::find()
            ->where(['conversationId' => $conversationId, 'role' => 'user'])
            ->count();
    }

    public function getRecentMessageCount(string $sessionId, int $seconds = 60): int
    {
        $since = (new \DateTime())->modify("-{$seconds} seconds")->format('Y-m-d H:i:s');

        return (int)(new \yii\db\Query())
            ->from('{{%aiagent_messages}} m')
            ->innerJoin('{{%aiagent_conversations}} c', 'm.conversationId = c.id')
            ->where(['c.sessionId' => $sessionId, 'm.role' => 'user'])
            ->andWhere(['>=', 'm.dateCreated', $since])
            ->count();
    }

    public function markEscalated(int $conversationId, string $reason = ''): void
    {
        ConversationRecord::updateAll(
            ['status' => 'escalated', 'metadata' => json_encode(['escalation_reason' => $reason])],
            ['id' => $conversationId]
        );
    }
}
