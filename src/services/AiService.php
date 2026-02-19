<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use widewebpro\aiagent\Plugin;

class AiService extends Component
{
    /**
     * Two-step AI pipeline: validation/tool-selection then context/answer.
     * Returns non-streaming response (used by POST /chat).
     */
    public function processMessage(string $userMessage, array $conversationHistory, string $pageUrl = ''): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $provider = Plugin::getInstance()->provider;
        $toolRegistry = Plugin::getInstance()->tools;

        // Step 1: Validation & Tool Selection
        $step1Messages = $this->_buildStep1Messages($userMessage, $conversationHistory, $pageUrl);
        $toolSchemas = $toolRegistry->getSchemas();

        $step1Response = $provider->chat($step1Messages, $toolSchemas, [
            'temperature' => 0.3,
        ]);

        // Handle classification responses
        if (empty($step1Response['tool_calls']) && $step1Response['text']) {
            $text = strtolower(trim($step1Response['text']));

            if (str_contains($text, '[off_topic]')) {
                return [
                    'text' => $settings->fallbackMessage,
                    'tool_calls' => [],
                    'tool_results' => [],
                    'usage' => $step1Response['usage'] ?? [],
                ];
            }

            if (str_contains($text, '[greeting]')) {
                // Skip tools, go straight to step 2 for a friendly response
                $step2Messages = $this->_buildGreetingMessages($userMessage, $conversationHistory, $settings);
                $step2Response = $provider->chat($step2Messages, [], [
                    'temperature' => $settings->temperature,
                ]);
                return [
                    'text' => $step2Response['text'] ?? 'Hello! How can I help you?',
                    'tool_calls' => [],
                    'tool_results' => [],
                    'usage' => [
                        'step1' => $step1Response['usage'] ?? [],
                        'step2' => $step2Response['usage'] ?? [],
                    ],
                ];
            }

            // LLM didn't call tools but should have — force a KB search as fallback
            $step1Response['tool_calls'] = [[
                'id' => 'fallback_kb_search',
                'name' => 'search_knowledge_base',
                'arguments' => ['query' => $userMessage, 'limit' => 5],
            ]];
        }

        // Execute tool calls
        $toolResults = $toolRegistry->executeToolCalls($step1Response['tool_calls']);

        // Step 2: Generate answer with context from tool results
        $step2Messages = $this->_buildStep2Messages(
            $userMessage,
            $conversationHistory,
            $step1Response['tool_calls'] ?? [],
            $toolResults,
            $pageUrl
        );

        $step2Response = $provider->chat($step2Messages, [], [
            'temperature' => $settings->temperature,
        ]);

        return [
            'text' => $step2Response['text'] ?? $settings->errorMessage,
            'tool_calls' => $step1Response['tool_calls'] ?? [],
            'tool_results' => $toolResults,
            'usage' => [
                'step1' => $step1Response['usage'] ?? [],
                'step2' => $step2Response['usage'] ?? [],
            ],
        ];
    }

    /**
     * Streaming two-step pipeline. Step 1 runs non-streaming (tool selection),
     * Step 2 streams the answer.
     */
    public function processMessageStreaming(string $userMessage, array $conversationHistory, string $pageUrl = ''): \Generator
    {
        $settings = Plugin::getInstance()->getSettings();
        $provider = Plugin::getInstance()->provider;
        $toolRegistry = Plugin::getInstance()->tools;

        // Step 1: Validation & Tool Selection (non-streaming)
        $step1Messages = $this->_buildStep1Messages($userMessage, $conversationHistory, $pageUrl);
        $toolSchemas = $toolRegistry->getSchemas();

        $step1Response = $provider->chat($step1Messages, $toolSchemas, [
            'temperature' => 0.3,
        ]);

        if (empty($step1Response['tool_calls']) && $step1Response['text']) {
            $text = strtolower(trim($step1Response['text']));

            if (str_contains($text, '[off_topic]')) {
                yield ['type' => 'text_delta', 'data' => $settings->fallbackMessage];
                yield ['type' => 'done', 'data' => ['tool_calls' => [], 'tool_results' => []]];
                return;
            }

            if (str_contains($text, '[greeting]')) {
                $greetingMessages = $this->_buildGreetingMessages($userMessage, $conversationHistory, $settings);
                foreach ($provider->stream($greetingMessages) as $chunk) {
                    yield $chunk;
                }
                return;
            }

            // LLM didn't call tools but should have — force a KB search as fallback
            $step1Response['tool_calls'] = [[
                'id' => 'fallback_kb_search',
                'name' => 'search_knowledge_base',
                'arguments' => ['query' => $userMessage, 'limit' => 5],
            ]];
        }

        // Execute tools
        $toolResults = [];
        if (!empty($step1Response['tool_calls'])) {
            foreach ($step1Response['tool_calls'] as $call) {
                yield ['type' => 'tool_call', 'data' => ['tool' => $call['name'], 'args' => $call['arguments'] ?? []]];
            }

            $toolResults = $toolRegistry->executeToolCalls($step1Response['tool_calls']);

            foreach ($toolResults as $result) {
                yield ['type' => 'tool_result', 'data' => ['tool' => $result['name'], 'status' => 'ok']];
            }
        }

        // Step 2: Stream the answer
        $step2Messages = $this->_buildStep2Messages(
            $userMessage,
            $conversationHistory,
            $step1Response['tool_calls'] ?? [],
            $toolResults,
            $pageUrl
        );

        foreach ($provider->stream($step2Messages) as $chunk) {
            yield $chunk;
        }
    }

    private function _buildStep1Messages(string $userMessage, array $history, string $pageUrl): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $systemPrompt = $this->_buildStep1SystemPrompt($settings, $pageUrl);

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        $recentHistory = array_slice($history, -10);
        foreach ($recentHistory as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];
        return $messages;
    }

    private function _buildStep2Messages(string $userMessage, array $history, array $toolCalls, array $toolResults, string $pageUrl): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $contextParts = [];
        foreach ($toolResults as $result) {
            $contextParts[] = "--- Tool: {$result['name']} ---\n{$result['result']}";
        }
        $context = implode("\n\n", $contextParts);

        $systemPrompt = "You are {$settings->agentName}. {$settings->agentPersona}\n\n";
        $systemPrompt .= "Use the following context to answer the user's question. ";
        $systemPrompt .= "If the context doesn't contain relevant information, say so honestly.\n\n";
        $systemPrompt .= "CONTEXT:\n{$context}";

        if ($pageUrl) {
            $systemPrompt .= "\n\nThe user is currently on page: {$pageUrl}";
        }

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        $recentHistory = array_slice($history, -6);
        foreach ($recentHistory as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];
        return $messages;
    }

    private function _buildGreetingMessages(string $userMessage, array $history, object $settings): array
    {
        $systemPrompt = "You are {$settings->agentName}. {$settings->agentPersona}\n";
        $systemPrompt .= "The user sent a greeting or casual message. Respond warmly and briefly, then offer to help.\n";
        $systemPrompt .= "Keep your response short — 1-2 sentences max. Be friendly and natural.";

        $messages = [['role' => 'system', 'content' => $systemPrompt]];

        $recentHistory = array_slice($history, -6);
        foreach ($recentHistory as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];
        return $messages;
    }

    private function _buildStep1SystemPrompt(object $settings, string $pageUrl): string
    {
        $prompt = "You are a routing assistant for {$settings->agentName}. Your job is to classify the user's message and call the right tools.\n\n";

        $prompt .= "MESSAGE CLASSIFICATION — pick ONE:\n\n";

        $prompt .= "A) GREETING or casual message (\"hello\", \"hi\", \"thanks\", \"how are you\", etc.)\n";
        $prompt .= "   → Respond with exactly: [GREETING]\n";
        $prompt .= "   Do NOT call any tools for greetings or pleasantries.\n\n";

        $prompt .= "B) QUESTION or request that needs information\n";
        $prompt .= "   → Call one or more tools to gather the information. Do NOT answer directly.\n\n";

        $prompt .= "C) OFF-TOPIC or DISALLOWED topic\n";
        $prompt .= "   → Respond with exactly: [OFF_TOPIC]\n\n";

        if ($settings->escalationEnabled) {
            $prompt .= "D) User wants to be connected to a human\n";
            $prompt .= "   → Call the escalate tool.\n";

            $sensitivity = $settings->escalationSensitivity ?? 'medium';
            if ($sensitivity === 'low') {
                $prompt .= "   STRICT: Only escalate when the user explicitly and firmly demands a human, person, agent, or representative.\n";
                $prompt .= "   Phrases like \"I need help\", \"can you help\", \"this isn't working\" are NOT escalation requests — always try to help first.\n";
                $prompt .= "   Even repeated frustration is not enough — the user must clearly say they want a human.\n\n";
            } elseif ($sensitivity === 'high') {
                $prompt .= "   SENSITIVE: Escalate when the user asks for a human OR seems frustrated, dissatisfied, or you've failed to answer their question after the conversation shows repeated attempts.\n";
                $prompt .= "   Signs to escalate: repeated complaints, expressions of frustration (\"this is useless\", \"you're not helping\"), or saying \"I need help\" after you already tried.\n";
                $prompt .= "   Still try to help first on the initial ask — don't escalate on the very first message.\n\n";
            } else {
                $prompt .= "   BALANCED: Escalate when the user clearly asks for human help (e.g. \"talk to someone\", \"connect me to support\", \"I want a real person\").\n";
                $prompt .= "   \"I need help\" or \"can you help me\" is NOT a request for a human — try to help first.\n";
                $prompt .= "   If you've tried to help and the user is still unsatisfied and asks again, then escalate.\n\n";
            }
        }

        // Inject available KB files
        $kbFiles = \widewebpro\aiagent\records\KnowledgeFileRecord::find()
            ->where(['status' => 'ready'])
            ->all();

        if (!empty($kbFiles)) {
            $prompt .= "KNOWLEDGE BASE FILES AVAILABLE:\n";
            foreach ($kbFiles as $file) {
                $prompt .= "- {$file->originalName} ({$file->chunkCount} chunks)\n";
            }
            $prompt .= "\n";
        }

        if ($settings->allowedTopics) {
            $prompt .= "ALLOWED TOPICS (only these are on-topic):\n{$settings->allowedTopics}\n\n";
        }

        if ($settings->disallowedTopics) {
            $prompt .= "DISALLOWED TOPICS (refuse these — classify as OFF-TOPIC):\n{$settings->disallowedTopics}\n\n";
        }

        $prompt .= "TOOL SELECTION RULES (for category B messages):\n";
        $prompt .= "1. For questions that could be answered by documents, call search_knowledge_base. This is your PRIMARY source.\n";
        $prompt .= "2. Also call get_business_info if asking about the company, contact info, hours, etc.\n";
        $prompt .= "3. Also call get_page_context if the question relates to the current page.\n";
        $prompt .= "4. Call list_knowledge_topics if unsure what information is available.\n";
        $prompt .= "5. You CAN call multiple tools in a single response.\n";
        $prompt .= "6. When in doubt, call search_knowledge_base — better to search and find nothing than to skip it.\n";

        if ($pageUrl) {
            $prompt .= "\nThe user is currently on page: {$pageUrl}";
        }

        return $prompt;
    }
}
