<?php

namespace widewebpro\aiassistant;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use craft\web\twig\variables\CraftVariable;
use widewebpro\aiassistant\models\Settings;
use widewebpro\aiassistant\services\AiService;
use widewebpro\aiassistant\services\ChatService;
use widewebpro\aiassistant\services\EmbeddingService;
use widewebpro\aiassistant\services\KnowledgeBaseService;
use widewebpro\aiassistant\services\ProviderService;
use widewebpro\aiassistant\services\ToolRegistry;
use widewebpro\aiassistant\services\WebhookService;
use widewebpro\aiassistant\services\WidgetService;
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
    public bool $hasCpSettings = true;

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
                'webhook' => WebhookService::class,
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
        $this->_registerPermissions();
    }

    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser();

        $subnav = [];
        if ($user->checkPermission('aiAssistant:viewConversations')) {
            $subnav['dashboard'] = ['label' => 'Dashboard', 'url' => 'craft-ai-assistant'];
            $subnav['conversations'] = ['label' => 'Conversations', 'url' => 'craft-ai-assistant/conversations'];
        }
        if ($user->checkPermission('aiAssistant:manageSettings')) {
            $subnav['settings'] = ['label' => 'Settings', 'url' => 'craft-ai-assistant/settings'];
        } elseif ($user->checkPermission('aiAssistant:manageKnowledgeBase')) {
            $subnav['settings'] = ['label' => 'Settings', 'url' => 'craft-ai-assistant/settings/knowledge-base'];
        }

        if (empty($subnav)) {
            return null;
        }

        $nav = parent::getCpNavItem();
        $nav['label'] = $this->getSettings()->agentName ?: 'AI Assistant';
        $nav['subnav'] = $subnav;
        $nav['url'] = reset($subnav)['url'];

        return $nav;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'craft-ai-assistant/settings/settings',
            ['settings' => $this->getSettings()],
            View::TEMPLATE_MODE_CP
        );
    }

    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();

        $settings = $this->getSettings();
        if (!$settings) {
            return;
        }

        Craft::$app->getProjectConfig()->set(
            ProjectConfig::PATH_PLUGINS . '.' . $this->handle . '.settings',
            ProjectConfigHelper::packAssociativeArrays($settings->toArray()),
            "Change settings for plugin “{$this->handle}”"
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                // Main nav
                $event->rules['craft-ai-assistant'] = 'craft-ai-assistant/default/index';
                $event->rules['craft-ai-assistant/conversations'] = 'craft-ai-assistant/conversations/index';
                $event->rules['craft-ai-assistant/conversations/<conversationId:\d+>'] = 'craft-ai-assistant/conversations/view';

                // Settings sub-tabs
                $event->rules['craft-ai-assistant/settings'] = 'craft-ai-assistant/settings/index';
                $event->rules['craft-ai-assistant/settings/save'] = 'craft-ai-assistant/settings/save';
                $event->rules['craft-ai-assistant/settings/appearance'] = 'craft-ai-assistant/appearance/index';
                $event->rules['craft-ai-assistant/settings/appearance/save'] = 'craft-ai-assistant/appearance/save';
                $event->rules['craft-ai-assistant/settings/knowledge-base'] = 'craft-ai-assistant/knowledge-base/index';
                $event->rules['craft-ai-assistant/settings/knowledge-base/upload'] = 'craft-ai-assistant/knowledge-base/upload';
                $event->rules['craft-ai-assistant/settings/knowledge-base/delete/<fileId:\d+>'] = 'craft-ai-assistant/knowledge-base/delete';
                $event->rules['craft-ai-assistant/settings/knowledge-base/reprocess/<fileId:\d+>'] = 'craft-ai-assistant/knowledge-base/reprocess';
                $event->rules['craft-ai-assistant/settings/pages'] = 'craft-ai-assistant/pages/index';
                $event->rules['craft-ai-assistant/settings/pages/save'] = 'craft-ai-assistant/pages/save';
                $event->rules['craft-ai-assistant/settings/restrictions'] = 'craft-ai-assistant/restrictions/index';
                $event->rules['craft-ai-assistant/settings/restrictions/save'] = 'craft-ai-assistant/restrictions/save';
                $event->rules['craft-ai-assistant/settings/escalation'] = 'craft-ai-assistant/escalation/index';
                $event->rules['craft-ai-assistant/settings/escalation/save'] = 'craft-ai-assistant/escalation/save';
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'AI Assistant',
                    'permissions' => [
                        'aiAssistant:viewConversations' => [
                            'label' => 'View dashboard and conversations',
                        ],
                        'aiAssistant:manageKnowledgeBase' => [
                            'label' => 'Manage the knowledge base',
                        ],
                        'aiAssistant:manageSettings' => [
                            'label' => 'Manage settings',
                            'warning' => 'Grants access to the AI provider API key.',
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['craft-ai-assistant/chat'] = 'craft-ai-assistant/chat-api/send';
                $event->rules['craft-ai-assistant/chat/stream'] = 'craft-ai-assistant/chat-api/stream';
                $event->rules['craft-ai-assistant/widget-config'] = 'craft-ai-assistant/chat-api/widget-config';
                $event->rules['craft-ai-assistant/escalate'] = 'craft-ai-assistant/chat-api/escalate';
                $event->rules['craft-ai-assistant/avatar'] = 'craft-ai-assistant/chat-api/avatar';
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
