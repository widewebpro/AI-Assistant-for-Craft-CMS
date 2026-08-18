<?php

namespace widewebpro\aiassistant\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use widewebpro\aiassistant\Plugin;
use widewebpro\aiassistant\records\KnowledgeFileRecord;
use yii\console\ExitCode;

/**
 * Knowledge-base maintenance.
 */
class KbController extends Controller
{
    public $defaultAction = 'reindex';

    public function actionReindex(): int
    {
        $files = KnowledgeFileRecord::find()->orderBy(['id' => SORT_ASC])->all();

        if (empty($files)) {
            $this->stdout("The knowledge base is empty — nothing to reindex.\n");
            return ExitCode::OK;
        }

        $settings = Plugin::getInstance()->getSettings();
        $chunkTotal = 0;

        $this->stdout("Knowledge-base files:\n", Console::BOLD);
        foreach ($files as $file) {
            $chunkTotal += (int)$file->chunkCount;
            $this->stdout(sprintf("  %-45s %-12s %d chunk(s)\n", $file->originalName, "[{$file->status}]", $file->chunkCount));
        }

        $this->stdout("\nReindexing will re-chunk all " . count($files) . " file(s) and re-embed ~{$chunkTotal} chunk(s)\n");
        $this->stdout("via the embedding API ({$settings->embeddingModel}). This costs tokens.\n");

        if (!$this->confirm('Proceed?')) {
            $this->stdout("Aborted.\n");
            return ExitCode::OK;
        }

        $kb = Plugin::getInstance()->knowledgeBase;
        $ok = 0;
        $failed = 0;

        foreach ($files as $file) {
            $this->stdout("Reindexing {$file->originalName} ... ");
            try {
                $kb->reprocessFile($file->id);
                $file->refresh();
                $this->stdout("done ({$file->chunkCount} chunk(s))\n", Console::FG_GREEN);
                $ok++;
            } catch (\Throwable $e) {
                $this->stdout("FAILED: {$e->getMessage()}\n", Console::FG_RED);
                $failed++;
            }
        }

        $this->stdout("\n{$ok} reindexed, {$failed} failed.\n", $failed > 0 ? Console::FG_RED : Console::FG_GREEN);

        return $failed > 0 ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
