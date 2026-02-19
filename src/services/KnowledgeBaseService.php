<?php

namespace craftcms\aiagent\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use craftcms\aiagent\Plugin;
use craftcms\aiagent\records\KnowledgeFileRecord;
use craftcms\aiagent\records\KnowledgeChunkRecord;

class KnowledgeBaseService extends Component
{
    private const CHUNK_SIZE = 500;     // ~500 tokens target
    private const CHUNK_OVERLAP = 50;   // ~50 tokens overlap
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    public function getStoragePath(): string
    {
        $path = Craft::$app->getPath()->getStoragePath() . '/ai-agent/knowledge-base';
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
        return $path;
    }

    /**
     * Process an uploaded file: store, extract text, chunk, and generate embeddings.
     */
    public function processUploadedFile(\yii\web\UploadedFile $file): KnowledgeFileRecord
    {
        if ($file->size > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('File exceeds maximum size of 10MB.');
        }

        $filename = StringHelper::UUID() . '.' . $file->getExtension();
        $storagePath = $this->getStoragePath();
        $filePath = $storagePath . '/' . $filename;

        $file->saveAs($filePath);

        $record = new KnowledgeFileRecord();
        $record->filename = $filename;
        $record->originalName = $file->name;
        $record->mimeType = $file->type;
        $record->fileSize = $file->size;
        $record->status = 'processing';
        $record->uid = StringHelper::UUID();
        $record->save(false);

        try {
            $this->_processFile($record, $filePath);
        } catch (\Throwable $e) {
            $record->status = 'error';
            $record->save(false);
            Craft::error("KB file processing failed: " . $e->getMessage(), 'ai-agent');
            throw $e;
        }

        return $record;
    }

    /**
     * Reprocess an existing file (re-chunk and re-embed).
     */
    public function reprocessFile(int $fileId): void
    {
        $record = KnowledgeFileRecord::findOne($fileId);
        if (!$record) {
            throw new \RuntimeException('File not found.');
        }

        // Delete existing chunks and embeddings (cascade)
        KnowledgeChunkRecord::deleteAll(['fileId' => $fileId]);

        $filePath = $this->getStoragePath() . '/' . $record->filename;
        if (!file_exists($filePath)) {
            $record->status = 'error';
            $record->save(false);
            throw new \RuntimeException('Source file not found on disk.');
        }

        $record->status = 'processing';
        $record->save(false);

        $this->_processFile($record, $filePath);
    }

    /**
     * Delete a file and all associated data.
     */
    public function deleteFile(int $fileId): void
    {
        $record = KnowledgeFileRecord::findOne($fileId);
        if (!$record) {
            return;
        }

        $filePath = $this->getStoragePath() . '/' . $record->filename;
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        $record->delete();
    }

    private function _processFile(KnowledgeFileRecord $record, string $filePath): void
    {
        $text = $this->_extractText($filePath, $record->mimeType);

        if (empty(trim($text))) {
            $record->status = 'error';
            $record->chunkCount = 0;
            $record->save(false);
            throw new \RuntimeException('No text content could be extracted from the file.');
        }

        $chunks = $this->_chunkText($text);
        $record->chunkCount = count($chunks);

        // Save chunks to DB
        $chunkRecords = [];
        foreach ($chunks as $i => $chunkText) {
            $chunk = new KnowledgeChunkRecord();
            $chunk->fileId = $record->id;
            $chunk->content = $chunkText;
            $chunk->chunkIndex = $i;
            $chunk->tokenCount = $this->_estimateTokens($chunkText);
            $chunk->metadata = json_encode(['filename' => $record->originalName]);
            $chunk->uid = StringHelper::UUID();
            $chunk->save(false);
            $chunkRecords[] = $chunk;
        }

        // Generate embeddings in batches
        try {
            $embeddingService = Plugin::getInstance()->embedding;
            $embeddingService->generateEmbeddingsForChunks($chunkRecords);
        } catch (\Throwable $e) {
            Craft::warning("Embedding generation failed, KB will use keyword fallback: " . $e->getMessage(), 'ai-agent');
        }

        $record->status = 'ready';
        $record->save(false);
    }

    private function _extractText(string $filePath, string $mimeType): string
    {
        return match (true) {
            str_contains($mimeType, 'pdf') => $this->_extractPdf($filePath),
            str_contains($mimeType, 'wordprocessingml') || str_ends_with($filePath, '.docx') => $this->_extractDocx($filePath),
            str_contains($mimeType, 'text/') || str_ends_with($filePath, '.md') || str_ends_with($filePath, '.txt') => file_get_contents($filePath),
            default => file_get_contents($filePath),
        };
    }

    private function _extractPdf(string $filePath): string
    {
        $parser = new \Smalot\PdfParser\Parser();
        $pdf = $parser->parseFile($filePath);
        return $pdf->getText();
    }

    private function _extractDocx(string $filePath): string
    {
        $phpWord = \PhpOffice\PhpWord\IOFactory::load($filePath);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text .= $this->_extractPhpWordElement($element) . "\n";
            }
        }

        return $text;
    }

    private function _extractPhpWordElement($element): string
    {
        if (method_exists($element, 'getText')) {
            return $element->getText();
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->_extractPhpWordElement($child);
            }
            return implode(' ', $parts);
        }

        return '';
    }

    /**
     * Recursive text chunking: split by paragraphs first, then sentences, with overlap.
     */
    private function _chunkText(string $text): array
    {
        $text = preg_replace('/\r\n?/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        $paragraphs = preg_split('/\n\n+/', $text);
        $chunks = [];
        $currentChunk = '';

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if (empty($para)) continue;

            $paraTokens = $this->_estimateTokens($para);

            if ($paraTokens > self::CHUNK_SIZE) {
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }

                $sentenceChunks = $this->_chunkBySentences($para);
                foreach ($sentenceChunks as $sc) {
                    $chunks[] = $sc;
                }
                continue;
            }

            $combinedTokens = $this->_estimateTokens($currentChunk . "\n\n" . $para);

            if ($combinedTokens > self::CHUNK_SIZE && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);

                // Overlap: keep last portion of previous chunk
                $words = explode(' ', $currentChunk);
                $overlapWords = array_slice($words, -self::CHUNK_OVERLAP);
                $currentChunk = implode(' ', $overlapWords) . "\n\n" . $para;
            } else {
                $currentChunk .= (empty($currentChunk) ? '' : "\n\n") . $para;
            }
        }

        if (!empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    private function _chunkBySentences(string $text): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $chunks = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $combined = $current . ' ' . $sentence;

            if ($this->_estimateTokens($combined) > self::CHUNK_SIZE && !empty($current)) {
                $chunks[] = trim($current);
                $current = $sentence;
            } else {
                $current = trim($combined);
            }
        }

        if (!empty(trim($current))) {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    private function _estimateTokens(string $text): int
    {
        return (int)ceil(str_word_count($text) * 1.3);
    }
}
