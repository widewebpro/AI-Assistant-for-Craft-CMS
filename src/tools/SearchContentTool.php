<?php

namespace widewebpro\aiagent\tools;

use Craft;
use craft\elements\Entry;
use widewebpro\aiagent\Plugin;

class SearchContentTool extends BaseTool
{
    private const MAX_RESULTS = 10;
    private const SNIPPET_LENGTH = 300;

    public function name(): string
    {
        return 'search_site_content';
    }

    public function description(): string
    {
        return "Search this website's own pages, articles and products by keyword. Returns page titles, URLs and short text snippets. Use it to find what content exists on the site.";
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Keywords to search the site content for',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of results (default 5, max 10)',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): string
    {
        $query = trim((string)($params['query'] ?? ''));
        if ($query === '') {
            return json_encode(['error' => 'Query is required']);
        }

        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->contentSearchEnabled) {
            return json_encode(['message' => 'Site content search is disabled.']);
        }

        $limit = min(max((int)($params['limit'] ?? 5), 1), self::MAX_RESULTS);

        $sections = $this->_allowedSections($settings->contentSearchSections);
        if ($sections === []) {
            return json_encode(['message' => 'No matching site content found.']);
        }

        $entries = $this->_search($query, $limit, $sections);

        if ($entries === [] && ($orQuery = $this->_orQuery($query)) !== null) {
            $entries = $this->_search($orQuery, $limit, $sections);
        }

        $results = [];
        foreach ($entries as $entry) {
            $results[] = array_filter([
                'title' => $entry->title,
                'url' => $entry->getUrl(),
                'section' => $entry->getSection()?->name,
                'snippet' => $this->_snippet($entry),
            ]);
        }

        if (empty($results)) {
            return json_encode(['message' => 'No matching site content found.']);
        }

        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return Entry[]
     */
    private function _search(string $search, int $limit, ?array $sections): array
    {
        $entryQuery = Entry::find()
            ->search($search)
            ->status(Entry::STATUS_LIVE)
            ->uri(':notempty:')
            ->orderBy('score')
            ->limit($limit);

        if ($sections !== null) {
            $entryQuery->section($sections);
        }

        return $entryQuery->all();
    }

    private function _orQuery(string $query): ?string
    {
        $terms = [];
        foreach (preg_split('/\s+/', $query) ?: [] as $term) {
            $term = trim($term, '"\'*-');
            if ($term === '' || in_array(strtolower($term), ['or', 'and', 'not'], true)) {
                continue;
            }
            $terms[] = $term;
        }

        return count($terms) >= 2 ? implode(' OR ', array_unique($terms)) : null;
    }

    private function _allowedSections(string $configured): ?array
    {
        $handles = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $configured))));
        if ($handles === []) {
            return null;
        }

        $existing = [];
        foreach ($handles as $handle) {
            if (Craft::$app->getEntries()->getSectionByHandle($handle) !== null) {
                $existing[] = $handle;
            }
        }

        return $existing;
    }

    private function _snippet(Entry $entry): string
    {
        $parts = [];
        foreach ($entry->getFieldLayout()?->getCustomFields() ?? [] as $field) {
            try {
                $value = $entry->getFieldValue($field->handle);
            } catch (\Throwable) {
                continue;
            }
            if ($value instanceof \craft\elements\db\ElementQuery) {
                continue;
            }
            if (is_string($value) || is_numeric($value) || $value instanceof \Stringable) {
                $value = trim(strip_tags((string)$value));
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
        }

        $snippet = implode(' — ', $parts);
        if (mb_strlen($snippet) > self::SNIPPET_LENGTH) {
            $snippet = mb_substr($snippet, 0, self::SNIPPET_LENGTH) . '…';
        }

        return $snippet;
    }
}
