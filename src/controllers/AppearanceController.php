<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use yii\web\Response;
use yii\web\UploadedFile;

class AppearanceController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('ai-agent/settings/appearance', [
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
        $settings->primaryColor = $this->_ensureHexColor($request->getBodyParam('primaryColor'), '#2563eb');
        $settings->secondaryColor = $this->_ensureHexColor($request->getBodyParam('secondaryColor'), '#f3f4f6');
        $settings->backgroundColor = $this->_ensureHexColor($request->getBodyParam('backgroundColor'), '#ffffff');
        $settings->textColor = $this->_ensureHexColor($request->getBodyParam('textColor'), '#1f2937');
        $settings->fontFamily = $request->getBodyParam('fontFamily') ?: '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
        $settings->widgetPosition = $request->getBodyParam('widgetPosition', 'bottom-right');
        $settings->welcomeMessage = $request->getBodyParam('welcomeMessage') ?: 'Hello! How can I help you today?';
        $settings->placeholderText = $request->getBodyParam('placeholderText') ?: 'Type your message...';
        $settings->customCss = $request->getBodyParam('customCss', '');
        $settings->customJs = $request->getBodyParam('customJs', '');

        // Avatar handling
        $removeAvatar = (bool)$request->getBodyParam('removeAvatar');
        if ($removeAvatar) {
            $this->_deleteAvatarFile();
            $settings->avatarUrl = '';
        }

        $avatarFile = UploadedFile::getInstanceByName('avatarFile');
        if ($avatarFile && !$avatarFile->getHasError()) {
            $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];
            $ext = strtolower($avatarFile->getExtension());
            if (in_array($ext, $allowedExtensions)) {
                $this->_deleteAvatarFile();
                $storagePath = Craft::$app->getPath()->getStoragePath() . '/ai-agent';
                if (!is_dir($storagePath)) {
                    mkdir($storagePath, 0775, true);
                }
                $avatarFile->saveAs($storagePath . '/avatar.' . $ext);
                $siteUrl = rtrim(Craft::$app->getSites()->getCurrentSite()->getBaseUrl(), '/');
                $settings->avatarUrl = $siteUrl . '/ai-agent/avatar';
            }
        } elseif (!$removeAvatar) {
            $manualUrl = trim($request->getBodyParam('avatarUrl', ''));
            if ($manualUrl !== '' && $manualUrl !== $settings->avatarUrl) {
                $this->_deleteAvatarFile();
                $settings->avatarUrl = $manualUrl;
            }
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            Craft::$app->getSession()->setError('Could not save appearance settings.');
            return null;
        }

        Craft::$app->getSession()->setNotice('Appearance settings saved.');
        return $this->redirect('ai-agent/settings/appearance');
    }

    private function _deleteAvatarFile(): void
    {
        $storagePath = Craft::$app->getPath()->getStoragePath() . '/ai-agent';
        $files = glob($storagePath . '/avatar.*');
        foreach ($files as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    private function _ensureHexColor(?string $value, string $default): string
    {
        if (empty($value)) {
            return $default;
        }

        $value = trim($value);

        if (!str_starts_with($value, '#')) {
            $value = '#' . $value;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }

        return $default;
    }
}
