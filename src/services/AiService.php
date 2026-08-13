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

        if (!$this->isInScope($userMessage, $conversationHistory)) {
            return [
                'text' => $settings->fallbackMessage,
                'tool_calls' => [],
                'tool_results' => [],
                'usage' => [],
            ];
        }

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

        if (!$this->isInScope($userMessage, $conversationHistory)) {
            yield ['type' => 'text_delta', 'data' => $settings->fallbackMessage];
            yield ['type' => 'done', 'data' => ['tool_calls' => [], 'tool_results' => []]];
            return;
        }

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
                yield from $this->_relayProviderStream($provider->stream($greetingMessages));
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

        yield from $this->_relayProviderStream($provider->stream($step2Messages));
    }

    private function _relayProviderStream(\Generator $stream): \Generator
    {
        foreach ($stream as $chunk) {
            if (($chunk['type'] ?? '') !== 'tool_calls') {
                yield $chunk;
                continue;
            }

            foreach ($chunk['data'] ?? [] as $call) {
                $args = $call['arguments'] ?? [];
                if (is_string($args)) {
                    $args = json_decode($args, true) ?? [];
                }
                yield ['type' => 'tool_call', 'data' => ['tool' => $call['name'] ?? '', 'args' => $args]];
            }
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

        $businessInfo = $this->_businessInfoBlock($settings);
        if ($businessInfo !== '') {
            $systemPrompt .= "BUSINESS INFORMATION (reliable facts about the business behind this website):\n{$businessInfo}\n\n";
        }

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

    private function _businessInfoBlock(object $settings): string
    {
        $fields = [
            'Name' => $settings->businessName,
            'About' => $settings->businessDescription,
            'Contact' => $settings->businessContact,
            'Hours' => $settings->businessHours,
            'Additional info' => $settings->businessExtra,
        ];

        $lines = [];
        foreach ($fields as $label => $value) {
            $value = trim((string)$value);
            if ($value !== '') {
                $lines[] = "{$label}: {$value}";
            }
        }

        return implode("\n", $lines);
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

    public function isInScope(string $userMessage, array $conversationHistory = []): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        $allowed = trim($settings->allowedTopics);

        if ($allowed === '') {
            return true;
        }

        $prompt = "You decide whether a message to a website assistant is within its permitted subject matter.\n\n"
            . "PERMITTED SUBJECTS — the complete list:\n{$allowed}\n\n"
            . "Answer IN_SCOPE if the message is about one of those subjects.\n"
            . "Answer OUT_OF_SCOPE for anything else. \"Anything else\" includes ordinary questions about the\n"
            . "business that are not named above — opening hours, contact details, pricing, delivery, returns,\n"
            . "the company itself — and any general knowledge question.\n"
            . "Answer IN_SCOPE for greetings, thanks, other pleasantries, and requests to speak to a human.\n"
            . "If the message is a follow-up, judge it by what the conversation is about.\n\n"
            . "Reply with exactly one word: IN_SCOPE or OUT_OF_SCOPE.";

        $messages = [['role' => 'system', 'content' => $prompt]];

        foreach (array_slice($conversationHistory, -4) as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            // No low output cap here: reasoning models (gpt-5*/o*, Claude with
            // adaptive thinking) spend token budget before emitting text, and a
            // tight cap starves them into an empty verdict — which fails open
            // and silently disables the whitelist. The reply is one word anyway.
            $response = Plugin::getInstance()->provider->chat($messages, [], [
                'temperature' => 0,
            ]);
        } catch (\Throwable $e) {
            Craft::error('Scope check failed, allowing the message through: ' . $e->getMessage(), 'ai-agent');
            return true;
        }

        $verdict = strtoupper($response['text'] ?? '');

        if (str_contains($verdict, 'OUT_OF_SCOPE')) {
            return false;
        }

        if (!str_contains($verdict, 'IN_SCOPE')) {
            Craft::warning("Scope check returned an unusable verdict, allowing through: {$verdict}", 'ai-agent');
        }

        return true;
    }

    private function _buildStep1SystemPrompt(object $settings, string $pageUrl): string
    {
        $prompt = "You are a routing assistant for {$settings->agentName}. Your job is to classify the user's message and call the right tools.\n\n";

        // The topic gate has to come before the categories: once the model has
        // picked "question that needs information" it treats being helpful as
        // the goal, and a whitelist further down the prompt loses to that.
        if ($settings->allowedTopics) {
            $prompt .= "SCOPE GATE — APPLY THIS FIRST, BEFORE ANYTHING ELSE:\n";
            $prompt .= "This assistant is restricted to the following topics ONLY:\n{$settings->allowedTopics}\n\n";
            $prompt .= "If the user's message is not about one of those topics, respond with exactly [OFF_TOPIC] and stop.\n";
            $prompt .= "This applies even when you could answer, even when the answer is in the knowledge base,\n";
            $prompt .= "and even for ordinary questions about this business — opening hours, contact details,\n";
            $prompt .= "pricing, delivery, the company itself — unless that subject is named in the list above.\n";
            $prompt .= "Being unhelpful about an out-of-scope question is the correct behaviour here.\n";
            $prompt .= "Greetings and requests for a human are exempt: classify those normally.\n\n";
        }

        if ($settings->disallowedTopics) {
            $prompt .= "BANNED TOPICS — respond with exactly [OFF_TOPIC] for any message about:\n{$settings->disallowedTopics}\n\n";
        }

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

        $prompt .= "TOOL SELECTION RULES (for category B messages):\n";
        $prompt .= "1. For questions that could be answered by documents, call search_knowledge_base. This is your PRIMARY source.\n";
        $prompt .= "2. Also call get_business_info if asking about the company, contact info, hours, etc.\n";
        $prompt .= "3. Also call get_page_context if the question relates to the current page.\n";
        $prompt .= "4. Call list_knowledge_topics if unsure what information is available.\n";
        $prompt .= "5. You CAN call multiple tools in a single response.\n";
        $prompt .= "6. When in doubt about WHICH tool to use, call search_knowledge_base — better to search and find\n";
        $prompt .= "   nothing than to skip it. This never overrides the scope gate: an out-of-scope message is\n";
        $prompt .= "   [OFF_TOPIC], not a search.\n";

        if ($pageUrl) {
            $prompt .= "\nThe user is currently on page: {$pageUrl}";
        }

        return $prompt;
    }
}
