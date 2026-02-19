<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use yii\web\Response;
use yii\web\UploadedFile;

class KnowledgeBaseController extends Controller
{
    public function actionIndex(): Response
    {
        return $this->renderTemplate('ai-agent/settings/knowledge-base', [
            'plugin' => Plugin::getInstance(),
        ]);
    }

    public function actionUpload(): ?Response
    {
        $this->requirePostRequest();

        $files = UploadedFile::getInstancesByName('kbFiles');

        if (empty($files)) {
            Craft::$app->getSession()->setError('No files selected.');
            return $this->redirectToPostedUrl();
        }

        $kb = Plugin::getInstance()->knowledgeBase;
        $processed = 0;
        $errors = [];

        foreach ($files as $file) {
            try {
                $kb->processUploadedFile($file);
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = $file->name . ': ' . $e->getMessage();
            }
        }

        if ($processed > 0) {
            Craft::$app->getSession()->setNotice("{$processed} file(s) processed successfully.");
        }

        if (!empty($errors)) {
            Craft::$app->getSession()->setError(implode("\n", $errors));
        }

        return $this->redirectToPostedUrl();
    }

    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $fileId = (int)Craft::$app->getRequest()->getRequiredBodyParam('fileId');

        Plugin::getInstance()->knowledgeBase->deleteFile($fileId);

        Craft::$app->getSession()->setNotice('File deleted.');
        return $this->redirectToPostedUrl();
    }

    public function actionReprocess(): ?Response
    {
        $this->requirePostRequest();
        $fileId = (int)Craft::$app->getRequest()->getRequiredBodyParam('fileId');

        try {
            Plugin::getInstance()->knowledgeBase->reprocessFile($fileId);
            Craft::$app->getSession()->setNotice('File reprocessed successfully.');
        } catch (\Throwable $e) {
            Craft::$app->getSession()->setError('Reprocessing failed: ' . $e->getMessage());
        }

        return $this->redirectToPostedUrl();
    }
}
