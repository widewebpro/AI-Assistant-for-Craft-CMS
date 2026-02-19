<?php

namespace craftcms\aiagent\controllers;

use Craft;
use craft\web\Controller;
use craftcms\aiagent\Plugin;
use yii\web\Response;

class RestrictionsController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('ai-agent/settings/restrictions', [
            'plugin' => Plugin::getInstance(),
            'settings' => Plugin::getInstance()->getSettings(),
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();

        $settings = $plugin->getSettings();
        $settings->allowedTopics = $request->getBodyParam('allowedTopics', '');
        $settings->disallowedTopics = $request->getBodyParam('disallowedTopics', '');
        $settings->fallbackMessage = $request->getBodyParam('fallbackMessage', '');
        $settings->errorMessage = $request->getBodyParam('errorMessage', '');
        $settings->maxMessagesPerConversation = (int)$request->getBodyParam('maxMessagesPerConversation', 50);
        $settings->rateLimitPerMinute = (int)$request->getBodyParam('rateLimitPerMinute', 10);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError('Could not save restriction settings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Restriction settings saved.');
        return $this->redirectToPostedUrl();
    }
}
