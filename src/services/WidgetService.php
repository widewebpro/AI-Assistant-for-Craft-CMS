<?php

namespace widewebpro\aiassistant\services;

use Craft;
use craft\base\Component;
use widewebpro\aiassistant\Plugin;

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
            'maxMessageLength' => $settings->maxMessageLength,
            'position' => $settings->widgetPosition,
            'theme' => [
                'primaryColor' => $settings->primaryColor,
                'secondaryColor' => $settings->secondaryColor,
                'backgroundColor' => $settings->backgroundColor,
                'primaryTextColor' => $settings->primaryTextColor,
                'secondaryTextColor' => $settings->secondaryTextColor,
                'fontFamily' => $settings->fontFamily,
            ],
            'customCss' => $settings->customCss,
            'customJs' => $settings->customJs,
            'endpoints' => [
                'chat' => rtrim($siteUrl, '/') . '/craft-ai-assistant/chat',
                'stream' => rtrim($siteUrl, '/') . '/craft-ai-assistant/chat/stream',
            ],
            'strings' => [
                'widgetAria' => Craft::t('craft-ai-assistant', '{name} Chat', ['name' => $settings->agentName]),
                'openChat' => Craft::t('craft-ai-assistant', 'Open chat'),
                'closeChat' => Craft::t('craft-ai-assistant', 'Close chat'),
                'messageAria' => Craft::t('craft-ai-assistant', 'Message'),
                'sendMessage' => Craft::t('craft-ai-assistant', 'Send message'),
                'online' => Craft::t('craft-ai-assistant', 'Online'),
                'searching' => Craft::t('craft-ai-assistant', 'Searching: {tool}…'),
                'errorGeneric' => Craft::t('craft-ai-assistant', 'An error occurred.'),
                'connectionLost' => Craft::t('craft-ai-assistant', 'Connection lost. Please try again.'),
                'unavailable' => Craft::t('craft-ai-assistant', 'The assistant is temporarily unavailable. Please try again later.'),
                'contactInformation' => Craft::t('craft-ai-assistant', 'Contact Information'),
                'submit' => Craft::t('craft-ai-assistant', 'Submit'),
                'submitting' => Craft::t('craft-ai-assistant', 'Submitting…'),
                'formError' => Craft::t('craft-ai-assistant', 'Sorry, there was an error submitting the form. Please try again.'),
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
window.__aiAssistantConfig = {$configJson};
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
