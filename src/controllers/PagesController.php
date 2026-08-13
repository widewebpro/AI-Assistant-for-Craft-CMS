<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use yii\web\Response;

class PagesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('aiAgent:manageSettings');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('ai-agent/settings/pages', [
            'plugin' => Plugin::getInstance(),
            'rules' => Plugin::getInstance()->getSettings()->pageRules,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $patterns = $request->getBodyParam('patterns', []);
        $ruleTypes = $request->getBodyParam('ruleTypes', []);

        $rules = [];
        foreach ($patterns as $i => $pattern) {
            $pattern = trim((string)$pattern);
            if ($pattern === '') {
                continue;
            }
            $rules[] = [
                'pattern' => $pattern,
                'ruleType' => ($ruleTypes[$i] ?? 'include') === 'exclude' ? 'exclude' : 'include',
            ];
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $settings->pageRules = $rules;

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError('Could not save page rules.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Page rules saved.');
        return $this->redirectToPostedUrl();
    }
}
