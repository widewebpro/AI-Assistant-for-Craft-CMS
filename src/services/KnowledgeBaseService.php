<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use widewebpro\aiagent\jobs\ProcessKnowledgeFileJob;
use widewebpro\aiagent\Plugin;
use widewebpro\aiagent\records\KnowledgeFileRecord;
use widewebpro\aiagent\records\KnowledgeChunkRecord;

class KnowledgeBaseService extends Component
{
    public const STUCK_AFTER_MINUTES = 10;

    private const CHUNK_SIZE = 500;     // ~500 tokens target
    private const CHUNK_OVERLAP = 50;   // ~50 tokens overlap
    private const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

    private const ALLOWED_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown', 'text/html'],
    ];

    public function getStoragePath(): string
    {
        $path = Craft::$app->getPath()->getStoragePath() . '/ai-agent/knowledge-base';
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
        return $path;
    }

    /**
     * Store an uploaded file and queue background processing (extract, chunk, embed).
     */
    public function processUploadedFile(\yii\web\UploadedFile $file): KnowledgeFileRecord
    {
        $realMime = $this->_validateUpload($file);

        $filename = StringHelper::UUID() . '.' . strtolower($file->getExtension());
        $storagePath = $this->getStoragePath();
        $filePath = $storagePath . '/' . $filename;

        $file->saveAs($filePath);

        $record = new KnowledgeFileRecord();
        $record->filename = $filename;
        $record->originalName = $file->name;
        $record->mimeType = $realMime;
        $record->fileSize = $file->size;
        $record->status = 'processing';
        $record->uid = StringHelper::UUID();
        $record->save(false);

        $this->queueProcessing($record);

        return $record;
    }

    private function _validateUpload(\yii\web\UploadedFile $file): string
    {
        if ($file->getHasError()) {
            throw new \RuntimeException("Upload of \"{$file->name}\" failed (error {$file->error}).");
        }

        if ($file->size > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('File exceeds maximum size of 10MB.');
        }

        $ext = strtolower($file->getExtension());
        if (!isset(self::ALLOWED_TYPES[$ext])) {
            throw new \RuntimeException("\"{$file->name}\": .{$ext} is not supported (allowed: pdf, docx, txt, md).");
        }

        $realMime = FileHelper::getMimeType($file->tempName, null, false);
        if (!in_array($realMime, self::ALLOWED_TYPES[$ext], true)) {
            throw new \RuntimeException("\"{$file->name}\" does not look like a .{$ext} file (detected: " . ($realMime ?? 'unknown') . ').');
        }

        return $realMime;
    }

    public function queueProcessing(KnowledgeFileRecord $record): void
    {
        Craft::$app->getQueue()->push(new ProcessKnowledgeFileJob([
            'fileId' => $record->id,
            'description' => "AI Agent: processing “{$record->originalName}”",
        ]));
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

        try {
            $this->_processFile($record, $filePath);
        } catch (\Throwable $e) {
            $record->status = 'error';
            $record->save(false);
            Craft::error("KB file reprocessing failed: " . $e->getMessage(), 'ai-agent');
            throw $e;
        }
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
        foreach ($chunks as $i => $chunkData) {
            $chunk = new KnowledgeChunkRecord();
            $chunk->fileId = $record->id;
            $chunk->content = $chunkData['content'];
            $chunk->chunkIndex = $i;
            $chunk->tokenCount = $this->_estimateTokens($chunkData['content']);
            $chunk->metadata = array_filter([
                'filename' => $record->originalName,
                'heading' => $chunkData['heading'],
            ]);
            $chunk->uid = StringHelper::UUID();
            $chunk->save(false);
            $chunkRecords[] = $chunk;
        }

        Plugin::getInstance()->embedding->generateEmbeddingsForChunks($chunkRecords);

        $record->status = 'ready';
        $record->save(false);
    }

    private function _extractText(string $filePath, string $mimeType): string
    {
        $text = match (true) {
            str_contains($mimeType, 'pdf') => $this->_extractPdf($filePath),
            str_contains($mimeType, 'wordprocessingml') || str_ends_with($filePath, '.docx') => $this->_extractDocx($filePath),
            str_contains($mimeType, 'text/') || str_ends_with($filePath, '.md') || str_ends_with($filePath, '.txt') => file_get_contents($filePath),
            default => file_get_contents($filePath),
        };

        return $this->_sanitizeUtf8($text);
    }

    private function _sanitizeUtf8(string $text): string
    {
        $text = preg_replace_callback(
            '/\xED[\xA0-\xAF][\x80-\xBF]\xED[\xB0-\xBF][\x80-\xBF]/',
            function (array $m) {
                $hi = ((ord($m[0][0]) & 0x0F) << 12) | ((ord($m[0][1]) & 0x3F) << 6) | (ord($m[0][2]) & 0x3F);
                $lo = ((ord($m[0][3]) & 0x0F) << 12) | ((ord($m[0][4]) & 0x3F) << 6) | (ord($m[0][5]) & 0x3F);
                return mb_chr(0x10000 + (($hi - 0xD800) << 10) + ($lo - 0xDC00), 'UTF-8');
            },
            $text
        );

        $sub = mb_substitute_character();
        mb_substitute_character('none');
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        mb_substitute_character($sub);

        return $text;
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

    private function _chunkText(string $text): array
    {
        $text = preg_replace('/\r\n?/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        $chunks = [];
        foreach ($this->_splitByHeadings($text) as $section) {
            foreach ($this->_chunkSection($section['body']) as $content) {
                $chunks[] = ['content' => $content, 'heading' => $section['heading']];
            }
        }

        return $chunks;
    }

    private function _splitByHeadings(string $text): array
    {
        $parts = preg_split('/^(#{1,6}[ \t]+\S.*)$/m', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $sections = [];
        $heading = null;
        $body = '';

        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                if (trim($body) !== '') {
                    $sections[] = ['heading' => $heading, 'body' => $body];
                }
                $heading = trim(ltrim($part, "# \t"));
                $body = $part;
            } else {
                $body .= $part;
            }
        }

        if (trim($body) !== '') {
            $sections[] = ['heading' => $heading, 'body' => $body];
        }

        return $sections ?: [['heading' => null, 'body' => $text]];
    }

    private function _chunkSection(string $text): array
    {
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
