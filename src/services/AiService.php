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

        // If the AI returned text without tool calls, check for off-topic
        if (empty($step1Response['tool_calls']) && $step1Response['text']) {
            $text = strtolower($step1Response['text']);
            if (str_contains($text, '[off_topic]')) {
                return [
                    'text' => $settings->fallbackMessage,
                    'tool_calls' => [],
                    'tool_results' => [],
                    'usage' => $step1Response['usage'] ?? [],
                ];
            }

            // LLM didn't call tools but should have -- force a KB search as fallback
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
            $text = strtolower($step1Response['text']);
            if (str_contains($text, '[off_topic]')) {
                yield ['type' => 'text_delta', 'data' => $settings->fallbackMessage];
                yield ['type' => 'done', 'data' => ['tool_calls' => [], 'tool_results' => []]];
                return;
            }

            // LLM didn't call tools but should have -- force a KB search as fallback
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

    private function _buildStep1SystemPrompt(object $settings, string $pageUrl): string
    {
        $prompt = "You are a tool-calling assistant. You do NOT answer questions directly.\n";
        $prompt .= "Your ONLY job is to:\n";
        $prompt .= "1. Check if the user's question is on-topic\n";
        $prompt .= "2. Call the right tools to gather information. You MUST call at least one tool for every on-topic question.\n";
        $prompt .= "3. NEVER generate an answer yourself. The answer will be generated in a later step using your tool results.\n\n";

        // Inject available KB files so the LLM knows what to search
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
            $prompt .= "DISALLOWED TOPICS (refuse these):\n{$settings->disallowedTopics}\n\n";
        }

        $prompt .= "TOOL SELECTION RULES (follow in order):\n";
        $prompt .= "1. If the question is off-topic or about a disallowed topic, respond with exactly: [OFF_TOPIC]\n";
        $prompt .= "2. For ANY question that could be answered by uploaded documents, ALWAYS call search_knowledge_base first. This is your PRIMARY information source.\n";
        $prompt .= "3. ALSO call get_business_info if the user asks about the company, contact info, hours, etc.\n";
        $prompt .= "4. ALSO call get_page_context if the question relates to the page the user is currently viewing.\n";
        $prompt .= "5. Call list_knowledge_topics if you are unsure what information is available.\n";
        $prompt .= "6. Call escalate if the user requests human help or you cannot assist.\n";
        $prompt .= "7. You CAN and SHOULD call multiple tools in a single response.\n";
        $prompt .= "8. When in doubt, ALWAYS call search_knowledge_base. It is better to search and find nothing than to skip it.\n";

        if ($pageUrl) {
            $prompt .= "\nThe user is currently on page: {$pageUrl}";
        }

        return $prompt;
    }
}
