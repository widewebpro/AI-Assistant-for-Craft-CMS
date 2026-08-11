<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use yii\web\Response;

class EscalationController extends Controller
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
        $settings = Plugin::getInstance()->getSettings();

        $fieldRows = [];
        foreach ($this->_ensureArray($settings->escalationFields) as $i => $field) {
            $fieldRows['row' . $i] = $field;
        }

        $actionRows = [];
        foreach ($this->_ensureArray($settings->escalationActions) as $i => $action) {
            $actionRows['row' . $i] = $action;
        }

        $fieldMapRows = [];
        foreach ($this->_ensureArray($settings->escalationFieldMap) as $i => $map) {
            $fieldMapRows['row' . $i] = $map;
        }

        return $this->renderTemplate('ai-agent/settings/escalation', [
            'plugin' => Plugin::getInstance(),
            'settings' => $settings,
            'fieldRows' => $fieldRows,
            'actionRows' => $actionRows,
            'fieldMapRows' => $fieldMapRows,
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
        $settings->escalationConfirmation = $request->getBodyParam('escalationConfirmation', '');

        $rawFields = $this->_ensureArray($request->getBodyParam('escalationFields'));
        $fields = [];
        foreach ($rawFields as $row) {
            if (is_array($row) && !empty(trim($row['label'] ?? ''))) {
                $fields[] = [
                    'label' => trim($row['label'] ?? ''),
                    'handle' => trim($row['handle'] ?? '') ?: $this->_toHandle(trim($row['label'] ?? '')),
                    'type' => $row['type'] ?? 'text',
                    'required' => !empty($row['required']),
                    'placeholder' => trim($row['placeholder'] ?? ''),
                    'options' => trim($row['options'] ?? ''),
                ];
            }
        }
        $settings->escalationFields = $fields;

        $rawActions = $this->_ensureArray($request->getBodyParam('escalationActions'));
        $actions = [];
        foreach ($rawActions as $row) {
            if (is_array($row) && !empty(trim($row['url'] ?? ''))) {
                $actions[] = [
                    'enabled' => !empty($row['enabled']),
                    'name' => trim($row['name'] ?? 'Webhook'),
                    'url' => trim($row['url'] ?? ''),
                    'method' => $row['method'] ?? 'POST',
                    'format' => $row['format'] ?? 'json',
                    'headers' => trim($row['headers'] ?? ''),
                ];
            }
        }
        $settings->escalationActions = $actions;

        $rawFieldMap = $this->_ensureArray($request->getBodyParam('escalationFieldMap'));
        $fieldMap = [];
        foreach ($rawFieldMap as $row) {
            if (is_array($row) && !empty(trim($row['formHandle'] ?? '')) && !empty(trim($row['externalName'] ?? ''))) {
                $fieldMap[] = [
                    'formHandle' => trim($row['formHandle']),
                    'externalName' => trim($row['externalName']),
                ];
            }
        }
        $settings->escalationFieldMap = $fieldMap;

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError('Could not save escalation settings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Escalation settings saved.');
        return $this->redirectToPostedUrl();
    }

    private function _ensureArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function _toHandle(string $label): string
    {
        $handle = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
        return trim($handle, '_');
    }
}
