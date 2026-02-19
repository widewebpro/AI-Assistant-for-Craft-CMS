<?php

namespace craftcms\aiagent\records;

use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property string $filename
 * @property string $originalName
 * @property string $mimeType
 * @property int $fileSize
 * @property string $status
 * @property int $chunkCount
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class KnowledgeFileRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%aiagent_knowledge_files}}';
    }

    public function getChunks(): \yii\db\ActiveQuery
    {
        return $this->hasMany(KnowledgeChunkRecord::class, ['fileId' => 'id'])
            ->orderBy(['chunkIndex' => SORT_ASC]);
    }
}
