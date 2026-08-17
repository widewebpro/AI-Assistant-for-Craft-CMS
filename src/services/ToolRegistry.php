<?php

namespace widewebpro\aiagent\services;

use craft\base\Component;
use widewebpro\aiagent\events\RegisterToolsEvent;
use widewebpro\aiagent\tools\BaseTool;
use widewebpro\aiagent\tools\SearchKnowledgeBaseTool;
use widewebpro\aiagent\tools\GetPageContextTool;
use widewebpro\aiagent\tools\GetBusinessInfoTool;
use widewebpro\aiagent\tools\ListKnowledgeTopicsTool;
use widewebpro\aiagent\tools\SearchContentTool;
use widewebpro\aiagent\tools\EscalateTool;

class ToolRegistry extends Component
{
    /**
     * @event RegisterToolsEvent Lets other plugins add, replace or remove chat tools.
     *
     * ```php
     * use widewebpro\aiagent\events\RegisterToolsEvent;
     * use widewebpro\aiagent\services\ToolRegistry;
     * use yii\base\Event;
     *
     * Event::on(ToolRegistry::class, ToolRegistry::EVENT_REGISTER_TOOLS,
     *     function(RegisterToolsEvent $e) {
     *         $e->tools[] = new SearchEntriesTool();
     *     }
     * );
     * ```
     */
    public const EVENT_REGISTER_TOOLS = 'registerTools';

    /** @var BaseTool[] */
    private array $_tools = [];

    public function init(): void
    {
        parent::init();
        $this->register(new SearchKnowledgeBaseTool());
        $this->register(new GetPageContextTool());
        $this->register(new GetBusinessInfoTool());
        $this->register(new ListKnowledgeTopicsTool());

        $settings = \widewebpro\aiagent\Plugin::getInstance()?->getSettings();
        if ($settings && $settings->contentSearchEnabled) {
            $this->register(new SearchContentTool());
        }
        if ($settings && $settings->escalationEnabled) {
            $this->register(new EscalateTool());
        }

        if ($this->hasEventHandlers(self::EVENT_REGISTER_TOOLS)) {
            $event = new RegisterToolsEvent(['tools' => $this->_tools]);
            $this->trigger(self::EVENT_REGISTER_TOOLS, $event);

            $this->_tools = [];
            foreach ($event->tools as $tool) {
                $this->register($tool);
            }
        }
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
