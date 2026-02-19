<?php

namespace widewebpro\aiagent\tools;

use Craft;
use widewebpro\aiagent\records\ConversationRecord;

class EscalateTool extends BaseTool
{
    public function name(): string
    {
        return 'escalate';
    }

    public function description(): string
    {
        return 'Escalate the conversation for human review. Use this when you cannot adequately help the user, they request to speak with a human, or the issue requires human intervention.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => [
                    'type' => 'string',
                    'description' => 'Brief reason for the escalation',
                ],
            ],
            'required' => ['reason'],
        ];
    }

    public function execute(array $params): string
    {
        $reason = $params['reason'] ?? 'User requested human assistance';

        Craft::info("Conversation escalated: {$reason}", 'ai-agent');

        return json_encode([
            'status' => 'escalated',
            'reason' => $reason,
            'message' => 'This conversation has been flagged for human review. A team member will follow up as soon as possible.',
        ]);
    }
}
