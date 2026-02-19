<?php

namespace craftcms\aiagent\tools;

use Craft;
use craftcms\aiagent\records\KnowledgeFileRecord;

class ListKnowledgeTopicsTool extends BaseTool
{
    public function name(): string
    {
        return 'list_knowledge_topics';
    }

    public function description(): string
    {
        return 'List available knowledge base files and their topics. Use this to understand what information is available before searching.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $params): string
    {
        $files = KnowledgeFileRecord::find()
            ->where(['status' => 'ready'])
            ->all();

        if (empty($files)) {
            return json_encode(['message' => 'No knowledge base files are available.']);
        }

        $topics = [];
        foreach ($files as $file) {
            $topics[] = [
                'name' => $file->originalName,
                'chunks' => $file->chunkCount,
                'type' => $file->mimeType,
            ];
        }

        return json_encode([
            'available_files' => $topics,
            'total_files' => count($topics),
        ], JSON_PRETTY_PRINT);
    }
}
