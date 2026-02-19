<?php

namespace widewebpro\aiagent\tools;

use widewebpro\aiagent\Plugin;

class GetBusinessInfoTool extends BaseTool
{
    public function name(): string
    {
        return 'get_business_info';
    }

    public function description(): string
    {
        return 'Get general business information including name, description, contact details, and hours. Use when users ask about the company, contact info, or business hours.';
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
        $settings = Plugin::getInstance()->getSettings();

        $info = [
            'name' => $settings->businessName ?: \Craft::$app->getSystemName(),
            'description' => $settings->businessDescription ?: '',
            'contact' => $settings->businessContact ?: '',
            'hours' => $settings->businessHours ?: '',
            'additional' => $settings->businessExtra ?: '',
            'site_url' => \Craft::$app->getSites()->getCurrentSite()->getBaseUrl(),
        ];

        $info = array_filter($info);

        if (empty($info)) {
            return json_encode(['message' => 'No business information has been configured.']);
        }

        return json_encode($info, JSON_PRETTY_PRINT);
    }
}
