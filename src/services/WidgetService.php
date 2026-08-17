<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use widewebpro\aiagent\Plugin;

class WidgetService extends Component
{
    public function getWidgetConfig(?string $forPath = null): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $siteUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();

        $config = [
            'enabled' => $settings->enabled,
            'agentName' => $settings->agentName,
            'avatarUrl' => $settings->avatarUrl,
            'welcomeMessage' => $settings->welcomeMessage,
            'placeholderText' => $settings->placeholderText,
            'position' => $settings->widgetPosition,
            'theme' => [
                'primaryColor' => $settings->primaryColor,
                'secondaryColor' => $settings->secondaryColor,
                'backgroundColor' => $settings->backgroundColor,
                'textColor' => $settings->textColor,
                'fontFamily' => $settings->fontFamily,
            ],
            'customCss' => $settings->customCss,
            'customJs' => $settings->customJs,
            'endpoints' => [
                'chat' => rtrim($siteUrl, '/') . '/ai-agent/chat',
                'stream' => rtrim($siteUrl, '/') . '/ai-agent/chat/stream',
            ],
            'strings' => [
                'widgetAria' => Craft::t('ai-agent', '{name} Chat', ['name' => $settings->agentName]),
                'openChat' => Craft::t('ai-agent', 'Open chat'),
                'closeChat' => Craft::t('ai-agent', 'Close chat'),
                'messageAria' => Craft::t('ai-agent', 'Message'),
                'sendMessage' => Craft::t('ai-agent', 'Send message'),
                'online' => Craft::t('ai-agent', 'Online'),
                'searching' => Craft::t('ai-agent', 'Searching: {tool}…'),
                'errorGeneric' => Craft::t('ai-agent', 'An error occurred.'),
                'connectionLost' => Craft::t('ai-agent', 'Connection lost. Please try again.'),
                'contactInformation' => Craft::t('ai-agent', 'Contact Information'),
                'submit' => Craft::t('ai-agent', 'Submit'),
                'submitting' => Craft::t('ai-agent', 'Submitting…'),
                'formError' => Craft::t('ai-agent', 'Sorry, there was an error submitting the form. Please try again.'),
            ],
            'escalation' => [
                'enabled' => $settings->escalationEnabled,
                'message' => $settings->escalationMessage,
                'confirmation' => $settings->escalationConfirmation,
                'fields' => array_map(function ($f) {
                    $field = [
                        'label' => $f['label'] ?? '',
                        'handle' => $f['handle'] ?? '',
                        'type' => $f['type'] ?? 'text',
                        'required' => !empty($f['required']),
                        'placeholder' => $f['placeholder'] ?? '',
                    ];
                    if (in_array($f['type'] ?? '', ['select', 'checkbox']) && !empty($f['options'])) {
                        $field['options'] = array_map('trim', explode(',', $f['options']));
                    }
                    return $field;
                }, $settings->escalationFields),
            ],
        ];

        if ($forPath !== null) {
            $config['showOnPage'] = $this->shouldShowOnPath($forPath);
        }

        return $config;
    }

    public function renderWidgetScript(?string $path = null): string
    {
        $config = $this->getWidgetConfig();

        if (!$config['enabled']) {
            return '';
        }

        // Excluded pages get no script at all — the rule list never reaches the client.
        $path ??= '/' . ltrim(Craft::$app->getRequest()->getPathInfo(), '/');
        if (!$this->shouldShowOnPath($path)) {
            return '';
        }

        $configJson = json_encode($config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
        $basePath = dirname(__DIR__) . '/web/assets/widget';
        $result = Craft::$app->getAssetManager()->publish($basePath);
        $widgetJsUrl = $result[1] . '/chat-widget.js';

        return <<<HTML
<script>
window.__aiAgentConfig = {$configJson};
</script>
<script src="{$widgetJsUrl}" defer></script>
HTML;
    }

    public function shouldShowOnPath(string $path): bool
    {
        $rules = Plugin::getInstance()->getSettings()->pageRules;

        if (empty($rules)) {
            return true;
        }

        $hasIncludes = false;
        foreach ($rules as $rule) {
            if (($rule['ruleType'] ?? '') === 'include') {
                $hasIncludes = true;
                break;
            }
        }

        $allowed = !$hasIncludes;
        foreach ($rules as $rule) {
            if ($this->_matchGlob((string)($rule['pattern'] ?? ''), $path)) {
                $allowed = ($rule['ruleType'] ?? '') === 'include';
            }
        }

        return $allowed;
    }

    /** Glob match: '**' crosses path segments, '*' stays within one. */
    private function _matchGlob(string $pattern, string $path): bool
    {
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*\*', '{{GLOBSTAR}}', $regex);
        $regex = str_replace('\*', '[^/]*', $regex);
        $regex = str_replace('{{GLOBSTAR}}', '.*', $regex);

        return (bool)preg_match('#^' . $regex . '$#', $path);
    }
}
