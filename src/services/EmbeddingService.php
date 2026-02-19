<?php

namespace widewebpro\aiagent\services;

use Craft;
use craft\base\Component;
use widewebpro\aiagent\Plugin;
use widewebpro\aiagent\records\KnowledgeChunkRecord;

class EmbeddingService extends Component
{
    private const BATCH_SIZE = 20;

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

            foreach ($batch as $i => $chunk) {
                if (!isset($embeddings[$i])) continue;

                $embeddingBinary = pack('f*', ...$embeddings[$i]);

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
        // Try embedding search first
        try {
            $results = $this->_embeddingSearch($query, $limit);
            if (!empty($results)) {
                return $results;
            }
        } catch (\Throwable $e) {
            Craft::warning("Embedding search failed, falling back to keyword: " . $e->getMessage(), 'ai-agent');
        }

        // Fallback: keyword (FULLTEXT) search
        return $this->_keywordSearch($query, $limit);
    }

    private function _embeddingSearch(string $query, int $limit): array
    {
        $provider = Plugin::getInstance()->provider;
        $queryEmbedding = $provider->embed($query);

        if (empty($queryEmbedding)) {
            return [];
        }

        // Load all embeddings from DB
        $rows = (new \yii\db\Query())
            ->select(['e.chunkId', 'e.embedding', 'c.content', 'c.fileId', 'c.metadata', 'f.originalName as filename'])
            ->from('{{%aiagent_embeddings}} e')
            ->innerJoin('{{%aiagent_knowledge_chunks}} c', 'c.id = e.chunkId')
            ->innerJoin('{{%aiagent_knowledge_files}} f', 'f.id = c.fileId')
            ->where(['f.status' => 'ready'])
            ->all();

        if (empty($rows)) {
            return [];
        }

        // Compute cosine similarity
        $scored = [];
        foreach ($rows as $row) {
            $storedEmbedding = array_values(unpack('f*', $row['embedding']));
            $score = $this->_cosineSimilarity($queryEmbedding, $storedEmbedding);

            $scored[] = [
                'content' => $row['content'],
                'filename' => $row['filename'],
                'chunkId' => $row['chunkId'],
                'score' => $score,
            ];
        }

        // Sort by score descending
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

    private function _cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $len = min(count($a), count($b));

        for ($i = 0; $i < $len; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }
}
