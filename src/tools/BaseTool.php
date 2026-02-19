<?php

namespace widewebpro\aiagent\tools;

abstract class BaseTool
{
    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * JSON Schema for tool parameters.
     * Return ['type' => 'object', 'properties' => [...], 'required' => [...]]
     */
    abstract public function parameters(): array;

    /**
     * Execute the tool with the given parameters and return a string result.
     */
    abstract public function execute(array $params): string;

    /**
     * Convert to the format expected by ProviderService.
     */
    public function toSchema(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'parameters' => $this->parameters(),
        ];
    }
}
