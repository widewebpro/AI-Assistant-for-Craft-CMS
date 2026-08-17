<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use widewebpro\aiagent\records\KnowledgeFileRecord;
use widewebpro\aiagent\services\KnowledgeBaseService;
use yii\web\Response;
use yii\web\UploadedFile;

class KnowledgeBaseController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('aiAgent:manageKnowledgeBase');
        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('ai-agent/settings/knowledge-base', [
            'plugin' => Plugin::getInstance(),
            'embeddingReady' => Plugin::getInstance()->provider->hasEmbeddingKey(),
            'files' => $this->_fileRows(),
        ]);
    }

    public function actionStatuses(): Response
    {
        $this->requireAcceptsJson();

        return $this->asJson([
            'files' => array_map(fn(array $row) => [
                'id' => (int)$row['id'],
                'status' => $row['status'],
                'chunks' => (int)$row['chunkCount'],
                'stuck' => $row['isStuck'],
            ], $this->_fileRows()),
        ]);
    }

    /** File rows for the CP list; 'processing' older than STUCK_AFTER_MINUTES is flagged stuck. */
    private function _fileRows(): array
    {
        $stuckBefore = DateTimeHelper::currentUTCDateTime()
            ->modify('-' . KnowledgeBaseService::STUCK_AFTER_MINUTES . ' minutes')
            ->format('Y-m-d H:i:s');

        $rows = (new Query())
            ->from('{{%aiagent_knowledge_files}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();

        foreach ($rows as &$row) {
            $row['isStuck'] = $row['status'] === 'processing' && $row['dateUpdated'] < $stuckBefore;
        }

        return $rows;
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
        $queued = 0;
        $errors = [];

        foreach ($files as $file) {
            try {
                $kb->processUploadedFile($file);
                $queued++;
            } catch (\Throwable $e) {
                $errors[] = $file->name . ': ' . $e->getMessage();
            }
        }

        if ($queued > 0) {
            Craft::$app->getSession()->setNotice("{$queued} file(s) uploaded and queued for processing.");
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

        $record = KnowledgeFileRecord::findOne($fileId);
        if (!$record) {
            Craft::$app->getSession()->setError('File not found.');
            return $this->redirectToPostedUrl();
        }

        Plugin::getInstance()->knowledgeBase->queueProcessing($record);
        Craft::$app->getSession()->setNotice('File queued for reprocessing.');

        return $this->redirectToPostedUrl();
    }
}
