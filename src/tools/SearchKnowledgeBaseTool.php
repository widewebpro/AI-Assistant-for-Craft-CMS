<?php

namespace widewebpro\aiagent\tools;

use widewebpro\aiagent\Plugin;

class SearchKnowledgeBaseTool extends BaseTool
{
    public function name(): string
    {
        return 'search_knowledge_base';
    }

    public function description(): string
    {
        return 'Search the knowledge base for relevant information. Use this when the user asks questions that might be answered by uploaded documents.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query to find relevant knowledge base content',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results to return (default: 5)',
                    'default' => 5,
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): string
    {
        $query = $params['query'] ?? '';
        $limit = $params['limit'] ?? 5;

        if (empty($query)) {
            return json_encode(['error' => 'Query is required']);
        }

        $results = Plugin::getInstance()->embedding->search($query, $limit);

        if (empty($results)) {
            return json_encode(['message' => 'No relevant information found in the knowledge base.']);
        }

        $output = [];
        foreach ($results as $result) {
            $output[] = [
                'content' => $result['content'],
                'source' => $result['filename'] ?? 'Unknown',
                'relevance' => round($result['score'] ?? 0, 4),
            ];
        }

        return json_encode($output, JSON_PRETTY_PRINT);
    }
}
