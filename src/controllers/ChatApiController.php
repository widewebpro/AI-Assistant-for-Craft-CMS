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
    protected array|bool|int $allowAnonymous = ['send', 'stream', 'widget-config', 'escalate', 'avatar'];
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
        } elseif (!$plugin->chat->isValidSessionId($sessionId)) {
            throw new BadRequestHttpException('Invalid session ID.');
        }

        $ip = $request->getUserIP();

        // Before the conversation/provider, so a blocked request is free.
        if ($limit = $this->_limitError($sessionId, $ip)) {
            if ($limit['status'] === 'closed') {
                return $this->asJson([
                    'text' => $limit['message'],
                    'sessionId' => $sessionId,
                    'status' => 'closed',
                ]);
            }
            throw new TooManyRequestsHttpException($limit['message']);
        }

        $conversation = $plugin->chat->getOrCreateConversation($sessionId, $pageUrl, $ip);

        $history = $plugin->chat->getConversationHistory($conversation->id);

        $plugin->chat->addMessage($conversation->id, 'user', $message);

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

            // Save assistant message. Arrays, not json_encode: the columns are
            // json-typed, so the record layer encodes — a pre-encoded string
            // gets encoded twice and stores a JSON string scalar.
            $assistantMsg = $plugin->chat->addMessage($conversation->id, 'assistant', $result['text'], [
                'toolCalls' => $result['tool_calls'] ?: null,
                'toolResults' => $result['tool_results'] ?: null,
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

        if (!$plugin->chat->isValidSessionId($sessionId)) {
            $this->_sendSSE('error', ['message' => 'Invalid session ID.']);
            $this->_sendSSE('done', []);
            exit;
        }

        $ip = $request->getUserIP();

        // Before the conversation/provider, so a blocked request is free.
        if ($limit = $this->_limitError($sessionId, $ip)) {
            $this->_sendSSE('error', ['message' => $limit['message'], 'status' => $limit['status']]);
            $this->_sendSSE('done', []);
            exit;
        }

        $conversation = $plugin->chat->getOrCreateConversation($sessionId, $pageUrl, $ip);

        $history = $plugin->chat->getConversationHistory($conversation->id);
        $plugin->chat->addMessage($conversation->id, 'user', $message);

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

            // Save assistant response (arrays — the json columns encode themselves)
            $assistantMsg = $plugin->chat->addMessage($conversation->id, 'assistant', $fullText, [
                'toolCalls' => $allToolCalls ?: null,
                'toolResults' => $allToolResults ?: null,
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
     * GET /ai-agent/widget-config — Widget configuration for headless front ends.
     * Page rules stay on the server; pass ?path=/some/page to get a 'showOnPage' verdict.
     */
    public function actionWidgetConfig(): Response
    {
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $path = '/' . ltrim((string)$request->getQueryParam('path', '/'), '/');
        $config = $plugin->widget->getWidgetConfig($path);

        $headers = Craft::$app->getResponse()->getHeaders();
        if ($allow = $this->_corsOriginFor($request->getOrigin())) {
            $headers->set('Access-Control-Allow-Origin', $allow);
            if ($allow !== '*') {
                $headers->set('Vary', 'Origin');
            }
        }

        return $this->asJson($config);
    }

    /**
     * CORS decision from the corsAllowedOrigins setting: null = no header
     * (same-origin only), '*' = anyone, else the echoed request origin if listed.
     */
    private function _corsOriginFor(?string $requestOrigin): ?string
    {
        $raw = (string)Plugin::getInstance()->getSettings()->corsAllowedOrigins;
        $origins = array_values(array_filter(array_map('trim', preg_split('/\R+/', $raw))));

        if (empty($origins)) {
            return null;
        }

        if (in_array('*', $origins, true)) {
            return '*';
        }

        if ($requestOrigin !== null && in_array(rtrim($requestOrigin, '/'), $origins, true)) {
            return $requestOrigin;
        }

        return null;
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

        if (empty($sessionId) || !$plugin->chat->isValidSessionId($sessionId)) {
            return $this->asJson(['error' => 'Session ID required.', 'status' => 'error']);
        }

        $conversation = \widewebpro\aiagent\records\ConversationRecord::find()
            ->where(['sessionId' => $sessionId])
            ->one();

        if (!$conversation) {
            return $this->asJson(['error' => 'Conversation not found.', 'status' => 'error']);
        }

        $metadata = $conversation->metadata;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['contact'] = $contactData;
        $conversation->metadata = $metadata;
        $conversation->status = 'escalated';
        $conversation->save(false);

        $plugin->chat->addMessage($conversation->id, 'system', 'Escalation form submitted: ' . json_encode($contactData));

        // Fire webhook actions
        $conversationMeta = [
            'conversationId' => $conversation->id,
            'sessionId' => $sessionId,
            'pageUrl' => $conversation->pageUrl ?? '',
            'ipAddress' => $conversation->ipAddress ?? '',
            'escalatedAt' => date('c'),
        ];
        $webhookResults = $plugin->webhook->fireActions($contactData, $conversationMeta);

        if (!empty($webhookResults)) {
            $metadata['webhookResults'] = $webhookResults;
            $conversation->metadata = $metadata;
            $conversation->save(false);
        }

        Craft::info("Escalation form submitted for conversation {$conversation->id}", 'ai-agent');

        return $this->asJson([
            'status' => 'ok',
            'confirmation' => $settings->escalationConfirmation,
        ]);
    }

    /**
     * First tripped limit as ['message' => ..., 'status' => 'error'|'closed'], else null.
     * Shared by send and stream. Per-IP and daily limits are off at 0.
     */
    private function _limitError(string $sessionId, string $ip): ?array
    {
        $chat = Plugin::getInstance()->chat;
        $settings = Plugin::getInstance()->getSettings();

        if ($chat->getRecentMessageCount($sessionId) >= $settings->rateLimitPerMinute) {
            return ['message' => 'Rate limit exceeded. Please wait a moment.', 'status' => 'error'];
        }

        $ipLimit = (int)$settings->rateLimitPerMinutePerIp;
        if ($ipLimit > 0 && $chat->getRecentMessageCountByIp($ip) >= $ipLimit) {
            return ['message' => 'Rate limit exceeded. Please wait a moment.', 'status' => 'error'];
        }

        $dailyLimit = (int)$settings->dailyMessageLimit;
        if ($dailyLimit > 0 && $chat->getDailyMessageCount() >= $dailyLimit) {
            return ['message' => 'The assistant is temporarily unavailable due to high demand. Please try again later.', 'status' => 'error'];
        }

        $conversation = \widewebpro\aiagent\records\ConversationRecord::find()
            ->where(['sessionId' => $sessionId])
            ->one();
        if ($conversation && $chat->getMessageCount($conversation->id) >= $settings->maxMessagesPerConversation) {
            return ['message' => 'This conversation has reached its message limit. Please start a new conversation.', 'status' => 'closed'];
        }

        return null;
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

    /**
     * GET /ai-agent/avatar — Serve the uploaded avatar image.
     */
    public function actionAvatar(): Response
    {
        $storagePath = Craft::$app->getPath()->getStoragePath() . '/ai-agent';
        $files = glob($storagePath . '/avatar.*');

        if (empty($files) || !file_exists($files[0])) {
            throw new \yii\web\NotFoundHttpException('Avatar not found.');
        }

        $filePath = $files[0];
        $mimeTypes = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!isset($mimeTypes[$ext])) {
            throw new \yii\web\NotFoundHttpException('Avatar not found.');
        }

        $response = Craft::$app->getResponse();
        $response->headers->set('Content-Type', $mimeTypes[$ext]);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'public, max-age=86400');
        $response->format = Response::FORMAT_RAW;
        $response->data = file_get_contents($filePath);

        return $response;
    }
}
