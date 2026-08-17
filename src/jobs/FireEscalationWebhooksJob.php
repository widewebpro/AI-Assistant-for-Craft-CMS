<?php

namespace widewebpro\aiagent\jobs;

use craft\queue\BaseJob;
use widewebpro\aiagent\Plugin;
use widewebpro\aiagent\records\ConversationRecord;

class FireEscalationWebhooksJob extends BaseJob
{
    public int $conversationId;
    public array $contactData = [];
    public array $conversationMeta = [];

    public function execute($queue): void
    {
        $results = Plugin::getInstance()->webhook->fireActions($this->contactData, $this->conversationMeta);
        if (empty($results)) {
            return;
        }

        $conversation = ConversationRecord::findOne($this->conversationId);
        if (!$conversation) {
            return;
        }

        $metadata = $conversation->metadata;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['webhookResults'] = $results;

        $conversation->metadata = $metadata;
        $conversation->save(false);
    }

    public function getTtr(): int
    {
        return 180;
    }

    protected function defaultDescription(): ?string
    {
        return 'AI Agent: escalation webhooks';
    }
}
