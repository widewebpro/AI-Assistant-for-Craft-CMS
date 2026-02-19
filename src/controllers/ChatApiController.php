<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\TooManyRequestsHttpException;

class ChatApiController extends Controller
{
    protected array|bool|int $allowAnonymous = ['send', 'stream', 'widget-config', 'escalate'];
    public $enableCsrfValidation = false;

    /**
     * POST /ai-agent/chat — Non-streaming chat endpoint.
     */
    public function actionSend(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->enabled || empty($settings->apiKey)) {
            return $this->asJson(['error' => 'AI Agent is not configured.', 'status' => 'error']);
        }

        $message = $request->getRequiredBodyParam('message');
        $sessionId = $request->getBodyParam('sessionId', '');
        $pageUrl = $request->getBodyParam('pageUrl', '');

        if (empty($message)) {
            throw new BadRequestHttpException('Message is required.');
        }

        if (empty($sessionId)) {
            $sessionId = \craft\helpers\StringHelper::UUID();
        }

        $ip = $request->getUserIP();
        $conversation = $plugin->chat->getOrCreateConversation($sessionId, $pageUrl, $ip);

        // Rate limiting
        $recentCount = $plugin->chat->getRecentMessageCount($sessionId);
        if ($recentCount >= $settings->rateLimitPerMinute) {
            throw new TooManyRequestsHttpException('Rate limit exceeded. Please wait a moment.');
        }

        // Max messages check
        $totalMessages = $plugin->chat->getMessageCount($conversation->id);
        if ($totalMessages >= $settings->maxMessagesPerConversation) {
            return $this->asJson([
                'text' => 'This conversation has reached its message limit. Please start a new conversation.',
                'sessionId' => $sessionId,
                'status' => 'closed',
            ]);
        }

        // Save user message
        $plugin->chat->addMessage($conversation->id, 'user', $message);

        // Get conversation history
        $history = $plugin->chat->getConversationHistory($conversation->id);

        try {
            $result = $plugin->ai->processMessage($message, $history, $pageUrl);

            // Check for escalation
            $wasEscalated = false;
            foreach ($result['tool_calls'] ?? [] as $call) {
                if ($call['name'] === 'escalate') {
                    $reason = $call['arguments']['reason'] ?? '';
                    $plugin->chat->markEscalated($conversation->id, $reason);
                    $wasEscalated = true;
                }
            }

            $tokensUsed = 0;
            foreach ($result['usage'] ?? [] as $step) {
                $tokensUsed += ($step['total_tokens'] ?? 0);
            }

            // Save assistant message
            $assistantMsg = $plugin->chat->addMessage($conversation->id, 'assistant', $result['text'], [
                'toolCalls' => !empty($result['tool_calls']) ? json_encode($result['tool_calls']) : null,
                'toolResults' => !empty($result['tool_results']) ? json_encode($result['tool_results']) : null,
                'tokensUsed' => $tokensUsed ?: null,
            ]);

            return $this->asJson([
                'text' => $result['text'],
                'messageId' => $assistantMsg->id,
                'sessionId' => $sessionId,
                'status' => $wasEscalated ? 'escalated' : 'ok',
            ]);
        } catch (\Throwable $e) {
            Craft::error('AI Agent error: ' . $e->getMessage(), 'ai-agent');

            return $this->asJson([
                'text' => $settings->errorMessage,
                'sessionId' => $sessionId,
                'status' => 'error',
            ]);
        }
    }

    /**
     * GET /ai-agent/chat/stream — SSE streaming endpoint.
     */
    public function actionStream(): void
    {
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $message = $request->getQueryParam('message', '');
        $sessionId = $request->getQueryParam('sessionId', '');
        $pageUrl = $request->getQueryParam('pageUrl', '');

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        if (!$settings->enabled || empty($settings->apiKey)) {
            $this->_sendSSE('error', ['message' => 'AI Agent is not configured.']);
            $this->_sendSSE('done', []);
            exit;
        }

        if (empty($message) || empty($sessionId)) {
            $this->_sendSSE('error', ['message' => 'Message and sessionId are required.']);
            $this->_sendSSE('done', []);
            exit;
        }

        $ip = $request->getUserIP();
        $conversation = $plugin->chat->getOrCreateConversation($sessionId, $pageUrl, $ip);

        // Rate limiting
        $recentCount = $plugin->chat->getRecentMessageCount($sessionId);
        if ($recentCount >= $settings->rateLimitPerMinute) {
            $this->_sendSSE('error', ['message' => 'Rate limit exceeded.']);
            $this->_sendSSE('done', []);
            exit;
        }

        // Save user message
        $plugin->chat->addMessage($conversation->id, 'user', $message);
        $history = $plugin->chat->getConversationHistory($conversation->id);

        try {
            $fullText = '';
            $allToolCalls = [];
            $allToolResults = [];

            foreach ($plugin->ai->processMessageStreaming($message, $history, $pageUrl) as $chunk) {
                $type = $chunk['type'];
                $data = $chunk['data'];

                switch ($type) {
                    case 'text_delta':
                        $fullText .= $data;
                        $this->_sendSSE('token', ['delta' => $data]);
                        break;

                    case 'tool_call':
                        $allToolCalls[] = $data;
                        $this->_sendSSE('tool_call', $data);
                        break;

                    case 'tool_result':
                        $allToolResults[] = $data;
                        $this->_sendSSE('tool_result', $data);
                        break;

                    case 'done':
                        if (isset($data['tool_calls'])) {
                            $allToolCalls = $data['tool_calls'];
                        }
                        if (isset($data['tool_results'])) {
                            $allToolResults = $data['tool_results'];
                        }
                        break;
                }

                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }

            // Check for escalation
            foreach ($allToolCalls as $call) {
                $name = $call['tool'] ?? $call['name'] ?? '';
                if ($name === 'escalate') {
                    $reason = $call['args']['reason'] ?? $call['arguments']['reason'] ?? '';
                    $plugin->chat->markEscalated($conversation->id, $reason);
                    $this->_sendSSE('escalation', ['reason' => $reason]);
                }
            }

            // Save assistant response
            $assistantMsg = $plugin->chat->addMessage($conversation->id, 'assistant', $fullText, [
                'toolCalls' => !empty($allToolCalls) ? json_encode($allToolCalls) : null,
                'toolResults' => !empty($allToolResults) ? json_encode($allToolResults) : null,
            ]);

            $this->_sendSSE('done', ['messageId' => $assistantMsg->id]);
        } catch (\Throwable $e) {
            Craft::error('AI Agent stream error: ' . $e->getMessage(), 'ai-agent');
            $this->_sendSSE('error', ['message' => $settings->errorMessage]);
            $this->_sendSSE('done', []);
        }

        exit;
    }

    /**
     * GET /ai-agent/widget-config — Returns widget configuration for the frontend.
     */
    public function actionWidgetConfig(): Response
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $config = $plugin->widget->getWidgetConfig();

        Craft::$app->getResponse()->getHeaders()->set('Access-Control-Allow-Origin', '*');

        return $this->asJson($config);
    }

    /**
     * POST /ai-agent/escalate — Save escalation contact form data.
     */
    public function actionEscalate(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $sessionId = $request->getBodyParam('sessionId', '');
        $contactData = $request->getBodyParam('contact', []);

        if (empty($sessionId)) {
            return $this->asJson(['error' => 'Session ID required.', 'status' => 'error']);
        }

        $conversation = \widewebpro\aiagent\records\ConversationRecord::find()
            ->where(['sessionId' => $sessionId])
            ->one();

        if (!$conversation) {
            return $this->asJson(['error' => 'Conversation not found.', 'status' => 'error']);
        }

        $metadata = $conversation->metadata ? json_decode($conversation->metadata, true) : [];
        $metadata['contact'] = $contactData;
        $conversation->metadata = json_encode($metadata);
        $conversation->status = 'escalated';
        $conversation->save(false);

        $plugin->chat->addMessage($conversation->id, 'system', 'Escalation form submitted: ' . json_encode($contactData));

        Craft::info("Escalation form submitted for conversation {$conversation->id}", 'ai-agent');

        return $this->asJson([
            'status' => 'ok',
            'confirmation' => $settings->escalationConfirmation,
        ]);
    }

    private function _sendSSE(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data) . "\n\n";

        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
