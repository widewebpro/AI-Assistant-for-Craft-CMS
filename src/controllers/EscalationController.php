<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use yii\web\Response;

class EscalationController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('ai-agent/settings/escalation', [
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
        $settings->escalationEnabled = (bool)$request->getBodyParam('escalationEnabled');
        $settings->escalationSensitivity = $request->getBodyParam('escalationSensitivity', 'medium');
        $settings->escalationMessage = $request->getBodyParam('escalationMessage', '');
        $settings->escalationFieldName = (bool)$request->getBodyParam('escalationFieldName');
        $settings->escalationFieldEmail = (bool)$request->getBodyParam('escalationFieldEmail');
        $settings->escalationFieldPhone = (bool)$request->getBodyParam('escalationFieldPhone');
        $settings->escalationCustomQuestions = $request->getBodyParam('escalationCustomQuestions', '');
        $settings->escalationConfirmation = $request->getBodyParam('escalationConfirmation', '');

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError('Could not save escalation settings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Escalation settings saved.');
        return $this->redirectToPostedUrl();
    }
}
