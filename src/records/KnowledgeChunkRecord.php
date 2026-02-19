<?php

namespace widewebpro\aiagent\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $fileId
 * @property string $content
 * @property int $chunkIndex
 * @property int $tokenCount
 * @property array|null $metadata
 * @property string $dateCreated
 */
class KnowledgeChunkRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%aiagent_knowledge_chunks}}';
    }

    public function getFile(): \yii\db\ActiveQuery
    {
        return $this->hasOne(KnowledgeFileRecord::class, ['id' => 'fileId']);
    }
}
