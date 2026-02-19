<?php

namespace widewebpro\aiagent;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use widewebpro\aiagent\models\Settings;
use widewebpro\aiagent\services\AiService;
use widewebpro\aiagent\services\ChatService;
use widewebpro\aiagent\services\EmbeddingService;
use widewebpro\aiagent\services\KnowledgeBaseService;
use widewebpro\aiagent\services\ProviderService;
use widewebpro\aiagent\services\ToolRegistry;
use widewebpro\aiagent\services\WidgetService;
use yii\base\Event;

/**
 * @property-read AiService $ai
 * @property-read ChatService $chat
 * @property-read EmbeddingService $embedding
 * @property-read KnowledgeBaseService $knowledgeBase
 * @property-read ProviderService $provider
 * @property-read ToolRegistry $tools
 * @property-read WidgetService $widget
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                'ai' => AiService::class,
                'chat' => ChatService::class,
                'embedding' => EmbeddingService::class,
                'knowledgeBase' => KnowledgeBaseService::class,
                'provider' => ProviderService::class,
                'tools' => ToolRegistry::class,
                'widget' => WidgetService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();
        $this->_registerFrontendWidget();
    }

    public function getCpNavItem(): ?array
    {
        $nav = parent::getCpNavItem();
        $nav['label'] = $this->getSettings()->agentName ?: 'AI Assistant';

        $nav['subnav'] = [
            'dashboard' => ['label' => 'Dashboard', 'url' => 'ai-agent'],
            'conversations' => ['label' => 'Conversations', 'url' => 'ai-agent/conversations'],
            'settings' => ['label' => 'Settings', 'url' => 'ai-agent/settings'],
        ];

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                // Main nav
                $event->rules['ai-agent'] = 'ai-agent/default/index';
                $event->rules['ai-agent/conversations'] = 'ai-agent/conversations/index';
                $event->rules['ai-agent/conversations/<conversationId:\d+>'] = 'ai-agent/conversations/view';

                // Settings sub-tabs
                $event->rules['ai-agent/settings'] = 'ai-agent/settings/index';
                $event->rules['ai-agent/settings/save'] = 'ai-agent/settings/save';
                $event->rules['ai-agent/settings/appearance'] = 'ai-agent/appearance/index';
                $event->rules['ai-agent/settings/appearance/save'] = 'ai-agent/appearance/save';
                $event->rules['ai-agent/settings/knowledge-base'] = 'ai-agent/knowledge-base/index';
                $event->rules['ai-agent/settings/knowledge-base/upload'] = 'ai-agent/knowledge-base/upload';
                $event->rules['ai-agent/settings/knowledge-base/delete/<fileId:\d+>'] = 'ai-agent/knowledge-base/delete';
                $event->rules['ai-agent/settings/knowledge-base/reprocess/<fileId:\d+>'] = 'ai-agent/knowledge-base/reprocess';
                $event->rules['ai-agent/settings/pages'] = 'ai-agent/pages/index';
                $event->rules['ai-agent/settings/pages/save'] = 'ai-agent/pages/save';
                $event->rules['ai-agent/settings/restrictions'] = 'ai-agent/restrictions/index';
                $event->rules['ai-agent/settings/restrictions/save'] = 'ai-agent/restrictions/save';
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['ai-agent/chat'] = 'ai-agent/chat-api/send';
                $event->rules['ai-agent/chat/stream'] = 'ai-agent/chat-api/stream';
                $event->rules['ai-agent/widget-config'] = 'ai-agent/chat-api/widget-config';
            }
        );
    }

    private function _registerFrontendWidget(): void
    {
        if (Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_END_BODY,
            function () {
                $settings = $this->getSettings();
                if (!$settings->enabled) {
                    return;
                }

                echo $this->widget->renderWidgetScript();
            }
        );
    }
}
