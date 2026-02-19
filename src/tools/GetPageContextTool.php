<?php

namespace widewebpro\aiagent\tools;

use Craft;
use GuzzleHttp\Client;

class GetPageContextTool extends BaseTool
{
    public function name(): string
    {
        return 'get_page_context';
    }

    public function description(): string
    {
        return 'Get information about the page the user is currently viewing. Returns the page title, headings, and text content.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL of the page to get context for',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute(array $params): string
    {
        $url = $params['url'] ?? '';

        if (empty($url)) {
            return json_encode(['error' => 'URL is required']);
        }

        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';

        $context = [
            'url' => $url,
            'path' => $path,
        ];

        // Try Craft element first
        $element = Craft::$app->getElements()->getElementByUri(ltrim($path, '/') ?: '__home__');

        if ($element) {
            $context['title'] = $element->title ?? '';
            $context['type'] = get_class($element);

            if (method_exists($element, 'getSection')) {
                $section = $element->getSection();
                if ($section) {
                    $context['section'] = $section->name;
                }
            }

            $fieldValues = [];
            foreach ($element->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                try {
                    $value = $element->getFieldValue($field->handle);
                    if (is_string($value) || is_numeric($value)) {
                        $fieldValues[$field->handle] = (string)$value;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            if (!empty($fieldValues)) {
                $context['fields'] = $fieldValues;
            }
        }

        // Always fetch the actual rendered page to get visible content
        try {
            $html = $this->_fetchPage($url);
            if ($html) {
                $extracted = $this->_extractContent($html);
                $context['page_title'] = $extracted['title'];
                $context['headings'] = $extracted['headings'];
                $context['text_content'] = $extracted['text'];
            }
        } catch (\Throwable $e) {
            Craft::warning("GetPageContext fetch failed: " . $e->getMessage(), 'ai-agent');
        }

        return json_encode($context, JSON_PRETTY_PRINT);
    }

    private function _fetchPage(string $url): ?string
    {
        try {
            $client = new Client(['timeout' => 5, 'verify' => false]);
            $response = $client->get($url, [
                'headers' => ['User-Agent' => 'AiAgent/1.0 (internal)'],
            ]);
            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function _extractContent(string $html): array
    {
        // Strip script/style tags
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<nav\b[^>]*>.*?<\/nav>/si', '', $html);

        // Extract title
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1])));
        }

        // Extract headings
        $headings = [];
        if (preg_match_all('/<h[1-6][^>]*>(.*?)<\/h[1-6]>/si', $html, $matches)) {
            foreach ($matches[1] as $h) {
                $text = trim(html_entity_decode(strip_tags($h)));
                if ($text) {
                    $headings[] = $text;
                }
            }
        }

        // Extract body text
        $bodyHtml = $html;
        if (preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $m)) {
            $bodyHtml = $m[1];
        }

        $text = strip_tags($bodyHtml);
        $text = html_entity_decode($text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        // Truncate to ~3000 chars to stay within reasonable token limits
        if (mb_strlen($text) > 3000) {
            $text = mb_substr($text, 0, 3000) . '...';
        }

        return [
            'title' => $title,
            'headings' => array_slice($headings, 0, 20),
            'text' => $text,
        ];
    }
}
