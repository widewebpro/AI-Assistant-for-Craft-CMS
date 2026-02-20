<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use widewebpro\aiagent\Plugin;

class WidgetService extends Component
{
    public function getWidgetConfig(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $siteUrl = Craft::$app->getSites()->getCurrentSite()->getBaseUrl();

        $rules = (new \yii\db\Query())
            ->select(['pattern', 'ruleType'])
            ->from('{{%aiagent_page_rules}}')
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        return [
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
            'pageRules' => $rules,
            'escalation' => [
                'enabled' => $settings->escalationEnabled,
                'message' => $settings->escalationMessage,
                'fields' => [
                    'name' => $settings->escalationFieldName,
                    'email' => $settings->escalationFieldEmail,
                    'phone' => $settings->escalationFieldPhone,
                ],
                'customQuestions' => array_filter(array_map('trim', explode("\n", $settings->escalationCustomQuestions))),
                'confirmation' => $settings->escalationConfirmation,
            ],
        ];
    }

    public function renderWidgetScript(): string
    {
        $config = $this->getWidgetConfig();

        if (!$config['enabled']) {
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
}
