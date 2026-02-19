<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('ai-agent/settings/index', [
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
        $settings->enabled = (bool)$request->getBodyParam('enabled');
        $settings->aiProvider = $request->getBodyParam('aiProvider', 'openai');
        $settings->apiKey = $request->getBodyParam('apiKey', '');
        $settings->openaiModel = $request->getBodyParam('openaiModel', 'gpt-4o-mini');
        $settings->anthropicModel = $request->getBodyParam('anthropicModel', 'claude-3-5-sonnet-latest');
        $settings->embeddingModel = $request->getBodyParam('embeddingModel', 'text-embedding-3-small');
        $settings->maxTokens = (int)$request->getBodyParam('maxTokens', 1024);
        $settings->temperature = (float)$request->getBodyParam('temperature', 0.7);
        $settings->agentName = $request->getBodyParam('agentName', 'AI Assistant');
        $settings->agentPersona = $request->getBodyParam('agentPersona', '');
        $settings->businessName = $request->getBodyParam('businessName', '');
        $settings->businessDescription = $request->getBodyParam('businessDescription', '');
        $settings->businessContact = $request->getBodyParam('businessContact', '');
        $settings->businessHours = $request->getBodyParam('businessHours', '');
        $settings->businessExtra = $request->getBodyParam('businessExtra', '');

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError('Could not save settings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Settings saved.');
        return $this->redirectToPostedUrl();
    }
}
