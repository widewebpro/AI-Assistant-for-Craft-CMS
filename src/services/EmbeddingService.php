<?php

namespace widewebpro\aiassistant\services;

use Craft;
use craft\base\Component;
use widewebpro\aiassistant\Plugin;
use widewebpro\aiassistant\records\KnowledgeChunkRecord;

class EmbeddingService extends Component
{
    private const BATCH_SIZE = 20;
    private const RRF_K = 60;
    private const CANDIDATE_POOL = 20;
    private const MMR_REDUNDANCY = 0.92;

    public int $searchBatchSize = 500;

    /**
     * Generate and store embeddings for an array of chunk records.
     */
    public function generateEmbeddingsForChunks(array $chunkRecords): void
    {
        $provider = Plugin::getInstance()->provider;
        $settings = Plugin::getInstance()->getSettings();

        $batches = array_chunk($chunkRecords, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            $texts = array_map(fn($c) => $this->_embeddingInput($c), $batch);
            $embeddings = $provider->embedBatch($texts);

            if (count($embeddings) < count($batch)) {
                throw new \RuntimeException('Embedding provider returned ' . count($embeddings) . ' of ' . count($batch) . ' vectors.');
            }

            foreach ($batch as $i => $chunk) {
                if (!isset($embeddings[$i])) continue;

                $embeddingBinary = pack('f*', ...$this->_normalize($embeddings[$i]));

                Craft::$app->getDb()->createCommand()
                    ->insert('{{%aiassistant_embeddings}}', [
                        'chunkId' => $chunk->id,
                        'embedding' => $embeddingBinary,
                        'model' => $settings->embeddingModel,
                        'dateCreated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
                        'uid' => \craft\helpers\StringHelper::UUID(),
                    ])
                    ->execute();
            }
        }
    }

    public function search(string $query, int $limit = 5): array
    {
        $pool = max(self::CANDIDATE_POOL, $limit);

        $vectorResults = null;
        try {
            $vectorResults = $this->_embeddingSearch($query, $pool);
        } catch (\Throwable $e) {
            Craft::warning("Embedding search failed, using keyword candidates only: " . $e->getMessage(), 'craft-ai-assistant');
        }

        $keywordResults = [];
        try {
            $keywordResults = $this->_keywordSearch($query, $pool);
        } catch (\Throwable $e) {
            Craft::warning("Keyword search failed: " . $e->getMessage(), 'craft-ai-assistant');
        }

        $candidates = $this->_rrfMerge($vectorResults ?? [], $keywordResults, $pool);

        return $this->_diversify($candidates, $limit);
    }

    private function _diversify(array $candidates, int $limit): array
    {
        if (count($candidates) <= $limit) {
            return $candidates;
        }

        $vectors = $this->_embeddingsForChunks(array_column($candidates, 'chunkId'));

        $selected = [];
        $deferred = [];

        foreach ($candidates as $candidate) {
            if (count($selected) === $limit) {
                break;
            }

            $vector = $vectors[$candidate['chunkId']] ?? null;
            $redundant = false;

            if ($vector !== null) {
                foreach ($selected as $pick) {
                    $pickVector = $vectors[$pick['chunkId']] ?? null;
                    if ($pickVector !== null && $this->_dot($vector, $pickVector) >= self::MMR_REDUNDANCY) {
                        $redundant = true;
                        break;
                    }
                }
            }

            if ($redundant) {
                $deferred[] = $candidate;
            } else {
                $selected[] = $candidate;
            }
        }

        foreach ($deferred as $candidate) {
            if (count($selected) === $limit) {
                break;
            }
            $selected[] = $candidate;
        }

        return $selected;
    }

    private function _embeddingsForChunks(array $chunkIds): array
    {
        if (empty($chunkIds)) {
            return [];
        }

        $rows = (new \yii\db\Query())
            ->select(['chunkId', 'embedding'])
            ->from('{{%aiassistant_embeddings}}')
            ->where(['chunkId' => $chunkIds, 'model' => Plugin::getInstance()->getSettings()->embeddingModel])
            ->all();

        $vectors = [];
        foreach ($rows as $row) {
            $vectors[$row['chunkId']] = array_values(unpack('f*', $row['embedding']));
        }

        return $vectors;
    }

    private function _rrfMerge(array $vectorResults, array $keywordResults, int $limit): array
    {
        $merged = [];

        foreach (['vector' => $vectorResults, 'keyword' => $keywordResults] as $branch => $results) {
            foreach ($results as $rank => $result) {
                $id = $result['chunkId'];

                if (!isset($merged[$id])) {
                    $merged[$id] = $result;
                    $merged[$id]['score'] = 0.0;
                    $merged[$id]['matchedBy'] = [];
                }

                $merged[$id]['score'] += 1 / (self::RRF_K + $rank + 1);
                $merged[$id]['matchedBy'][] = $branch;
            }
        }

        usort($merged, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($merged, 0, $limit);
    }

    private function _embeddingSearch(string $query, int $limit): ?array
    {
        $queryEmbedding = Plugin::getInstance()->provider->embed($query);
        return $this->_searchByVector($queryEmbedding, $limit);
    }

    private function _searchByVector(array $queryEmbedding, int $limit): ?array
    {
        if (empty($queryEmbedding)) {
            return null;
        }

        $queryEmbedding = $this->_normalize($queryEmbedding);
        $settings = Plugin::getInstance()->getSettings();
        $threshold = (float)$settings->searchMinScore;
        $batchSize = max(1, $this->searchBatchSize);

        $scored = [];
        $seenAny = false;
        $lastChunkId = 0;

        do {
            $rows = (new \yii\db\Query())
                ->select(['e.chunkId', 'e.embedding', 'c.content', 'c.fileId', 'c.metadata', 'f.originalName as filename'])
                ->from('{{%aiassistant_embeddings}} e')
                ->innerJoin('{{%aiassistant_knowledge_chunks}} c', 'c.id = e.chunkId')
                ->innerJoin('{{%aiassistant_knowledge_files}} f', 'f.id = c.fileId')
                ->where(['f.status' => 'ready', 'e.model' => $settings->embeddingModel])
                ->andWhere(['>', 'e.chunkId', $lastChunkId])
                ->orderBy(['e.chunkId' => SORT_ASC])
                ->limit($batchSize)
                ->all();

            foreach ($rows as $row) {
                $seenAny = true;
                $lastChunkId = (int)$row['chunkId'];

                $storedEmbedding = array_values(unpack('f*', $row['embedding']));
                $score = $this->_dot($queryEmbedding, $storedEmbedding);

                if ($score < $threshold) {
                    continue;
                }

                $scored[] = [
                    'content' => $row['content'],
                    'filename' => $row['filename'],
                    'chunkId' => $row['chunkId'],
                    'score' => $score,
                ];
            }
        } while (count($rows) === $batchSize);

        if (!$seenAny) {
            return null;
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    private function _keywordSearch(string $query, int $limit): array
    {
        $results = (new \yii\db\Query())
            ->select([
                'c.content',
                'f.originalName as filename',
                'c.id as chunkId',
                'MATCH(c.content) AGAINST(:query IN NATURAL LANGUAGE MODE) as score',
            ])
            ->from('{{%aiassistant_knowledge_chunks}} c')
            ->innerJoin('{{%aiassistant_knowledge_files}} f', 'f.id = c.fileId')
            ->where(['f.status' => 'ready'])
            ->andWhere('MATCH(c.content) AGAINST(:query IN NATURAL LANGUAGE MODE)', [':query' => $query])
            ->orderBy(['score' => SORT_DESC])
            ->limit($limit)
            ->all();

        return array_map(fn($r) => [
            'content' => $r['content'],
            'filename' => $r['filename'],
            'chunkId' => $r['chunkId'],
            'score' => (float)$r['score'],
        ], $results);
    }

    private function _embeddingInput($chunk): string
    {
        $meta = $chunk->metadata;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $meta = is_array($meta) ? $meta : [];

        $prefix = implode(' — ', array_filter([$meta['filename'] ?? null, $meta['heading'] ?? null]));
        $input = $prefix === '' ? $chunk->content : $prefix . ":\n" . $chunk->content;

        if (mb_strlen($input) > 16000) {
            $input = mb_substr($input, 0, 16000);
        }

        return $input;
    }

    /** Scale a vector to unit length; zero vectors are returned unchanged. */
    private function _normalize(array $v): array
    {
        $sum = 0.0;
        foreach ($v as $x) {
            $sum += $x * $x;
        }

        $norm = sqrt($sum);
        if ($norm == 0.0) {
            return $v;
        }

        return array_map(fn($x) => $x / $norm, $v);
    }

    private function _dot(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        $dot = 0.0;

        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
        }

        return $dot;
    }
}
