<?php

namespace craftcms\aiagent\services;

use craft\base\Component;
use craftcms\aiagent\tools\BaseTool;
use craftcms\aiagent\tools\SearchKnowledgeBaseTool;
use craftcms\aiagent\tools\GetPageContextTool;
use craftcms\aiagent\tools\GetBusinessInfoTool;
use craftcms\aiagent\tools\ListKnowledgeTopicsTool;
use craftcms\aiagent\tools\EscalateTool;

class ToolRegistry extends Component
{
    /** @var BaseTool[] */
    private array $_tools = [];

    public function init(): void
    {
        parent::init();
        $this->register(new SearchKnowledgeBaseTool());
        $this->register(new GetPageContextTool());
        $this->register(new GetBusinessInfoTool());
        $this->register(new ListKnowledgeTopicsTool());
        $this->register(new EscalateTool());
    }

    public function register(BaseTool $tool): void
    {
        $this->_tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?BaseTool
    {
        return $this->_tools[$name] ?? null;
    }

    /** @return BaseTool[] */
    public function all(): array
    {
        return $this->_tools;
    }

    /**
     * Get all tool schemas for the AI provider.
     */
    public function getSchemas(): array
    {
        $schemas = [];
        foreach ($this->_tools as $tool) {
            $schemas[] = $tool->toSchema();
        }
        return $schemas;
    }

    /**
     * Execute a tool call and return its result.
     */
    public function executeTool(string $name, array $params = []): string
    {
        $tool = $this->get($name);
        if (!$tool) {
            return json_encode(['error' => "Unknown tool: {$name}"]);
        }

        try {
            return $tool->execute($params);
        } catch (\Throwable $e) {
            \Craft::error("Tool '{$name}' failed: " . $e->getMessage(), 'ai-agent');
            return json_encode(['error' => "Tool execution failed: " . $e->getMessage()]);
        }
    }

    /**
     * Execute multiple tool calls, return array of results keyed by tool call ID.
     */
    public function executeToolCalls(array $toolCalls): array
    {
        $results = [];
        foreach ($toolCalls as $call) {
            $results[] = [
                'tool_call_id' => $call['id'] ?? '',
                'name' => $call['name'],
                'result' => $this->executeTool($call['name'], $call['arguments'] ?? []),
            ];
        }
        return $results;
    }
}
