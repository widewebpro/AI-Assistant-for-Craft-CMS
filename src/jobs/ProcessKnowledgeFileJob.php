<?php

namespace widewebpro\aiassistant\jobs;

use craft\queue\BaseJob;
use widewebpro\aiassistant\Plugin;
use widewebpro\aiassistant\records\KnowledgeFileRecord;
use yii\queue\RetryableJobInterface;

class ProcessKnowledgeFileJob extends BaseJob implements RetryableJobInterface
{
    public int $fileId;

    public function execute($queue): void
    {
        if (!KnowledgeFileRecord::findOne($this->fileId)) {
            return; // deleted while queued
        }

        $this->setProgress($queue, 0.05);
        Plugin::getInstance()->knowledgeBase->reprocessFile($this->fileId);
        $this->setProgress($queue, 1);
    }

    public function getTtr(): int
    {
        return 600;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < 3;
    }

    protected function defaultDescription(): ?string
    {
        return 'Processing AI Agent knowledge file';
    }
}
