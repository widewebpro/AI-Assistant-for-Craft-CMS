<?php

namespace widewebpro\aiagent\models;

use craft\base\Model;

class Settings extends Model
{
    // General
    public bool $enabled = false;
    public string $aiProvider = 'openai'; // openai | anthropic
    public string $apiKey = '';
    public string $openaiModel = 'gpt-4o-mini';
    public string $anthropicModel = 'claude-3-5-sonnet-latest';
    public string $embeddingModel = 'text-embedding-3-small';
    public int $maxTokens = 1024;
    public float $temperature = 0.7;
    public string $agentName = 'AI Assistant';
    public string $agentPersona = 'You are a helpful assistant. Answer questions clearly and concisely based on the provided context.';

    // Appearance
    public string $primaryColor = '#2563eb';
    public string $secondaryColor = '#f3f4f6';
    public string $backgroundColor = '#ffffff';
    public string $textColor = '#1f2937';
    public string $fontFamily = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
    public string $widgetPosition = 'bottom-right'; // bottom-right | bottom-left
    public string $welcomeMessage = 'Hello! How can I help you today?';
    public string $placeholderText = 'Type your message...';
    public string $customCss = '';
    public string $customJs = '';

    // Restrictions
    public string $allowedTopics = '';
    public string $disallowedTopics = '';
    public string $fallbackMessage = "I'm sorry, I can only help with topics related to this website. Is there anything else I can assist you with?";
    public string $errorMessage = "I'm sorry, something went wrong. Please try again later.";
    public int $maxMessagesPerConversation = 50;
    public int $rateLimitPerMinute = 10;

    // Escalation
    public bool $escalationEnabled = true;
    public string $escalationSensitivity = 'medium'; // low | medium | high
    public string $escalationMessage = 'Let me connect you with a team member. Please share your contact details so we can follow up.';
    public bool $escalationFieldName = true;
    public bool $escalationFieldEmail = true;
    public bool $escalationFieldPhone = false;
    public string $escalationCustomQuestions = '';
    public string $escalationConfirmation = 'Thank you! A team member will reach out to you shortly.';

    // Business Info (for get_business_info tool)
    public string $businessName = '';
    public string $businessDescription = '';
    public string $businessContact = '';
    public string $businessHours = '';
    public string $businessExtra = '';

    public function init(): void
    {
        parent::init();
        $this->primaryColor = $this->_normalizeColor($this->primaryColor, '#2563eb');
        $this->secondaryColor = $this->_normalizeColor($this->secondaryColor, '#f3f4f6');
        $this->backgroundColor = $this->_normalizeColor($this->backgroundColor, '#ffffff');
        $this->textColor = $this->_normalizeColor($this->textColor, '#1f2937');
    }

    private function _normalizeColor(string $value, string $default): string
    {
        $value = trim($value);
        if (empty($value)) {
            return $default;
        }
        if (!str_starts_with($value, '#')) {
            $value = '#' . $value;
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
            return $value;
        }
        return $default;
    }

    public function defineRules(): array
    {
        return [
            [['escalationSensitivity'], 'in', 'range' => ['low', 'medium', 'high']],
            [['aiProvider'], 'in', 'range' => ['openai', 'anthropic']],
            [['widgetPosition'], 'in', 'range' => ['bottom-right', 'bottom-left']],
            [['maxTokens'], 'integer', 'min' => 100, 'max' => 8192],
            [['temperature'], 'number', 'min' => 0, 'max' => 2],
            [['maxMessagesPerConversation'], 'integer', 'min' => 1, 'max' => 200],
            [['rateLimitPerMinute'], 'integer', 'min' => 1, 'max' => 60],
            [['agentName', 'primaryColor', 'welcomeMessage', 'fallbackMessage', 'errorMessage'], 'required'],
        ];
    }
}
