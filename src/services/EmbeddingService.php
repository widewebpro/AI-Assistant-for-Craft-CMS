<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use widewebpro\aiagent\Plugin;
use widewebpro\aiagent\records\KnowledgeChunkRecord;

class EmbeddingService extends Component
{
    private const BATCH_SIZE = 20;
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
            $texts = array_map(fn($c) => $c->content, $batch);
            $embeddings = $provider->embedBatch($texts);

            if (count($embeddings) < count($batch)) {
                throw new \RuntimeException('Embedding provider returned ' . count($embeddings) . ' of ' . count($batch) . ' vectors.');
            }

            foreach ($batch as $i => $chunk) {
                if (!isset($embeddings[$i])) continue;

                $embeddingBinary = pack('f*', ...$this->_normalize($embeddings[$i]));

                Craft::$app->getDb()->createCommand()
                    ->insert('{{%aiagent_embeddings}}', [
                        'chunkId' => $chunk->id,
                        'embedding' => $embeddingBinary,
                        'model' => $settings->embeddingModel,
                        'dateCreated' => (new \DateTime())->format('Y-m-d H:i:s'),
                        'uid' => \craft\helpers\StringHelper::UUID(),
                    ])
                    ->execute();
            }
        }
    }

    /**
     * Search knowledge base using embeddings with keyword fallback.
     */
    public function search(string $query, int $limit = 5): array
    {
        // Try embedding search first.
        try {
            $results = $this->_embeddingSearch($query, $limit);
            if ($results !== null) {
                return $results;
            }
        } catch (\Throwable $e) {
            Craft::warning("Embedding search failed, falling back to keyword: " . $e->getMessage(), 'ai-agent');
        }

        // Fallback: keyword (FULLTEXT) search
        return $this->_keywordSearch($query, $limit);
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
                ->from('{{%aiagent_embeddings}} e')
                ->innerJoin('{{%aiagent_knowledge_chunks}} c', 'c.id = e.chunkId')
                ->innerJoin('{{%aiagent_knowledge_files}} f', 'f.id = c.fileId')
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
            ->from('{{%aiagent_knowledge_chunks}} c')
            ->innerJoin('{{%aiagent_knowledge_files}} f', 'f.id = c.fileId')
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
