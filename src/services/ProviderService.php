<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use widewebpro\aiagent\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class ProviderService extends Component
{
    private ?Client $_client = null;

    private function _getClient(): Client
    {
        if ($this->_client === null) {
            $this->_client = new Client([
                'timeout' => 60,
                'connect_timeout' => 10,
            ]);
        }
        return $this->_client;
    }

    /**
     * Non-streaming chat completion. Returns parsed response with text and/or tool_calls.
     */
    public function chat(array $messages, array $tools = [], array $options = []): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $provider = $settings->aiProvider;

        if ($provider === 'anthropic') {
            return $this->_anthropicChat($messages, $tools, $options);
        }

        return $this->_openaiChat($messages, $tools, $options);
    }

    /**
     * Streaming chat completion. Yields chunks as associative arrays.
     * Each chunk: ['type' => 'text_delta'|'tool_call'|'done', 'data' => ...]
     */
    public function stream(array $messages, array $tools = [], array $options = []): \Generator
    {
        $settings = Plugin::getInstance()->getSettings();
        $provider = $settings->aiProvider;

        if ($provider === 'anthropic') {
            yield from $this->_anthropicStream($messages, $tools, $options);
        } else {
            yield from $this->_openaiStream($messages, $tools, $options);
        }
    }

    /**
     * Generate embedding vector for text. Always uses OpenAI embeddings API.
     */
    public function embed(string $text): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $response = $this->_getClient()->post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $settings->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $settings->embeddingModel,
                'input' => $text,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['data'][0]['embedding'] ?? [];
    }

    /**
     * Batch embed multiple texts in one request.
     */
    public function embedBatch(array $texts): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $response = $this->_getClient()->post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $settings->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $settings->embeddingModel,
                'input' => $texts,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $embeddings = [];
        foreach ($data['data'] ?? [] as $item) {
            $embeddings[] = $item['embedding'];
        }
        return $embeddings;
    }

    // ─── OpenAI ──────────────────────────────────────────────────

    private function _openaiChat(array $messages, array $tools, array $options): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $payload = [
            'model' => $settings->openaiModel,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? $settings->maxTokens,
            'temperature' => $options['temperature'] ?? $settings->temperature,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->_formatOpenAITools($tools);
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->_getClient()->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $settings->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $this->_parseOpenAIResponse($data);
    }

    private function _openaiStream(array $messages, array $tools, array $options): \Generator
    {
        $settings = Plugin::getInstance()->getSettings();
        $payload = [
            'model' => $settings->openaiModel,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? $settings->maxTokens,
            'temperature' => $options['temperature'] ?? $settings->temperature,
            'stream' => true,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $this->_formatOpenAITools($tools);
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->_getClient()->post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $settings->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'stream' => true,
        ]);

        $body = $response->getBody();
        $buffer = '';
        $toolCallBuffers = [];

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if (empty($line) || !str_starts_with($line, 'data: ')) {
                    continue;
                }

                $jsonStr = substr($line, 6);
                if ($jsonStr === '[DONE]') {
                    if (!empty($toolCallBuffers)) {
                        yield ['type' => 'tool_calls', 'data' => array_values($toolCallBuffers)];
                    }
                    yield ['type' => 'done', 'data' => null];
                    return;
                }

                $data = json_decode($jsonStr, true);
                if (!$data) continue;

                $delta = $data['choices'][0]['delta'] ?? [];

                if (isset($delta['content']) && $delta['content'] !== '') {
                    yield ['type' => 'text_delta', 'data' => $delta['content']];
                }

                if (isset($delta['tool_calls'])) {
                    foreach ($delta['tool_calls'] as $tc) {
                        $idx = $tc['index'];
                        if (!isset($toolCallBuffers[$idx])) {
                            $toolCallBuffers[$idx] = [
                                'id' => $tc['id'] ?? '',
                                'name' => $tc['function']['name'] ?? '',
                                'arguments' => '',
                            ];
                        }
                        if (isset($tc['function']['arguments'])) {
                            $toolCallBuffers[$idx]['arguments'] .= $tc['function']['arguments'];
                        }
                    }
                }
            }
        }
    }

    private function _formatOpenAITools(array $tools): array
    {
        $formatted = [];
        foreach ($tools as $tool) {
            $formatted[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
                ],
            ];
        }
        return $formatted;
    }

    private function _parseOpenAIResponse(array $data): array
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $result = [
            'text' => $message['content'] ?? null,
            'tool_calls' => [],
            'usage' => $data['usage'] ?? [],
            'finish_reason' => $choice['finish_reason'] ?? null,
        ];

        if (!empty($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {
                $result['tool_calls'][] = [
                    'id' => $tc['id'],
                    'name' => $tc['function']['name'],
                    'arguments' => json_decode($tc['function']['arguments'], true) ?? [],
                ];
            }
        }

        return $result;
    }

    // ─── Anthropic ───────────────────────────────────────────────

    private function _anthropicChat(array $messages, array $tools, array $options): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $systemPrompt = '';
        $anthropicMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt .= $msg['content'] . "\n";
            } else {
                $anthropicMessages[] = $msg;
            }
        }

        $payload = [
            'model' => $settings->anthropicModel,
            'max_tokens' => $options['max_tokens'] ?? $settings->maxTokens,
            'messages' => $anthropicMessages,
        ];

        if ($systemPrompt) {
            $payload['system'] = trim($systemPrompt);
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->_formatAnthropicTools($tools);
        }

        $response = $this->_getClient()->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $settings->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $this->_parseAnthropicResponse($data);
    }

    private function _anthropicStream(array $messages, array $tools, array $options): \Generator
    {
        $settings = Plugin::getInstance()->getSettings();

        $systemPrompt = '';
        $anthropicMessages = [];
        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt .= $msg['content'] . "\n";
            } else {
                $anthropicMessages[] = $msg;
            }
        }

        $payload = [
            'model' => $settings->anthropicModel,
            'max_tokens' => $options['max_tokens'] ?? $settings->maxTokens,
            'messages' => $anthropicMessages,
            'stream' => true,
        ];

        if ($systemPrompt) {
            $payload['system'] = trim($systemPrompt);
        }

        if (!empty($tools)) {
            $payload['tools'] = $this->_formatAnthropicTools($tools);
        }

        $response = $this->_getClient()->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $settings->apiKey,
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'stream' => true,
        ]);

        $body = $response->getBody();
        $buffer = '';
        $toolCalls = [];
        $currentToolInput = '';
        $currentToolId = '';
        $currentToolName = '';

        while (!$body->eof()) {
            $chunk = $body->read(1024);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if (empty($line) || !str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = json_decode(substr($line, 6), true);
                if (!$data) continue;

                $type = $data['type'] ?? '';

                if ($type === 'content_block_start') {
                    $block = $data['content_block'] ?? [];
                    if (($block['type'] ?? '') === 'tool_use') {
                        $currentToolId = $block['id'] ?? '';
                        $currentToolName = $block['name'] ?? '';
                        $currentToolInput = '';
                    }
                } elseif ($type === 'content_block_delta') {
                    $delta = $data['delta'] ?? [];
                    if (($delta['type'] ?? '') === 'text_delta') {
                        yield ['type' => 'text_delta', 'data' => $delta['text'] ?? ''];
                    } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                        $currentToolInput .= $delta['partial_json'] ?? '';
                    }
                } elseif ($type === 'content_block_stop') {
                    if ($currentToolName) {
                        $toolCalls[] = [
                            'id' => $currentToolId,
                            'name' => $currentToolName,
                            'arguments' => json_decode($currentToolInput, true) ?? [],
                        ];
                        $currentToolName = '';
                        $currentToolInput = '';
                    }
                } elseif ($type === 'message_stop') {
                    if (!empty($toolCalls)) {
                        yield ['type' => 'tool_calls', 'data' => $toolCalls];
                    }
                    yield ['type' => 'done', 'data' => null];
                    return;
                }
            }
        }
    }

    private function _formatAnthropicTools(array $tools): array
    {
        $formatted = [];
        foreach ($tools as $tool) {
            $formatted[] = [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
            ];
        }
        return $formatted;
    }

    private function _parseAnthropicResponse(array $data): array
    {
        $result = [
            'text' => null,
            'tool_calls' => [],
            'usage' => $data['usage'] ?? [],
            'finish_reason' => $data['stop_reason'] ?? null,
        ];

        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $result['text'] = ($result['text'] ?? '') . $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $result['tool_calls'][] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'arguments' => $block['input'] ?? [],
                ];
            }
        }

        return $result;
    }
}
