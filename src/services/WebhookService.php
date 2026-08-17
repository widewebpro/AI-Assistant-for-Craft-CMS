<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use widewebpro\aiagent\Plugin;

class WebhookService extends Component
{
    private const MAX_ERROR_LENGTH = 500;

    /**
     * Fire all enabled webhook actions for an escalation submission.
     *
     * @param array $contactData Raw form data keyed by field handle
     * @param array $conversationMeta Optional conversation metadata (id, sessionId, pageUrl, etc.)
     * @return array Results per action: [ ['name' => ..., 'success' => bool, 'status' => int, 'error' => string|null], ... ]
     */
    /** Whether any action would actually fire — lets callers skip queueing a no-op job. */
    public function hasEnabledActions(): bool
    {
        foreach (Plugin::getInstance()->getSettings()->escalationActions ?? [] as $action) {
            if (!empty($action['enabled']) && !empty($action['url'])) {
                return true;
            }
        }

        return false;
    }

    public function fireActions(array $contactData, array $conversationMeta = []): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $actions = $settings->escalationActions ?? [];
        $results = [];

        foreach ($actions as $action) {
            if (empty($action['enabled']) || empty($action['url'])) {
                continue;
            }

            $results[] = $this->_executeAction($action, $contactData, $conversationMeta);
        }

        return $results;
    }

    private function _executeAction(array $action, array $contactData, array $conversationMeta): array
    {
        $name = $action['name'] ?? 'Unnamed';

        try {
            $payload = $this->_buildPayload($action, $contactData, $conversationMeta);
            $headers = $this->_parseHeaders($action['headers'] ?? '');
            $method = strtoupper($action['method'] ?? 'POST');
            $format = $action['format'] ?? 'json';

            $options = ['timeout' => 15, 'connect_timeout' => 5];

            if ($format === 'json') {
                $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
                $options['json'] = $payload;
            } else {
                $headers['Content-Type'] = $headers['Content-Type'] ?? 'application/x-www-form-urlencoded';
                $options['form_params'] = $this->_flattenPayload($payload);
            }

            $options['headers'] = $headers;

            $client = new Client();
            $response = $client->request($method, $action['url'], $options);

            $statusCode = $response->getStatusCode();

            Craft::info("Webhook '{$name}' sent to {$action['url']} — HTTP {$statusCode}", 'ai-agent');

            return [
                'name' => $name,
                'success' => $statusCode >= 200 && $statusCode < 300,
                'status' => $statusCode,
                'error' => null,
            ];
        } catch (RequestException $e) {
            $status = $e->getResponse()?->getStatusCode() ?? 0;

            Craft::error("Webhook '{$name}' failed (HTTP {$status}): {$e->getMessage()}", 'ai-agent');

            return [
                'name' => $name,
                'success' => false,
                'status' => $status,
                'error' => $this->_truncate($e->getMessage()),
            ];
        } catch (\Throwable $e) {
            Craft::error("Webhook '{$name}' failed: {$e->getMessage()}", 'ai-agent');

            return [
                'name' => $name,
                'success' => false,
                'status' => 0,
                'error' => $this->_truncate($e->getMessage()),
            ];
        }
    }

    private function _truncate(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));

        if (mb_strlen($message) <= self::MAX_ERROR_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::MAX_ERROR_LENGTH) . '… (truncated)';
    }

    private function _buildPayload(array $action, array $contactData, array $conversationMeta): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $fieldMap = $this->_buildFieldMap($settings->escalationFieldMap ?? []);

        if (empty($fieldMap)) {
            return array_merge($contactData, [
                '_meta' => $conversationMeta,
            ]);
        }

        $payload = [];
        foreach ($fieldMap as $formHandle => $externalName) {
            if (isset($contactData[$formHandle])) {
                $this->_setNestedValue($payload, $externalName, $contactData[$formHandle]);
            }
        }

        if (isset($fieldMap['_meta'])) {
            $this->_setNestedValue($payload, $fieldMap['_meta'], $conversationMeta);
        }

        return $payload;
    }

    private function _buildFieldMap(array $mapRows): array
    {
        $map = [];
        foreach ($mapRows as $row) {
            $formHandle = trim($row['formHandle'] ?? '');
            $externalName = trim($row['externalName'] ?? '');
            if ($formHandle && $externalName) {
                $map[$formHandle] = $externalName;
            }
        }
        return $map;
    }

    /**
     * Parse header lines ("Key: Value") into an associative array.
     */
    private function _parseHeaders(string $raw): array
    {
        $headers = [];
        $lines = array_filter(array_map('trim', explode("\n", $raw)));

        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $headers;
    }

    /**
     * Set a value in a nested array using dot notation (e.g., "properties.email").
     */
    private function _setNestedValue(array &$array, string $path, mixed $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;

        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }

        $current = $value;
    }

    /**
     * Flatten nested arrays for form-encoded submissions.
     */
    private function _flattenPayload(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix ? "{$prefix}[{$key}]" : $key;
            if (is_array($value)) {
                $flat = array_merge($flat, $this->_flattenPayload($value, $fullKey));
            } else {
                $flat[$fullKey] = $value;
            }
        }
        return $flat;
    }
}
