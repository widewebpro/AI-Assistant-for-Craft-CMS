<?php

namespace widewebpro\aiassistant\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createConversationsTable();
        $this->_createMessagesTable();
        $this->_createKnowledgeFilesTable();
        $this->_createKnowledgeChunksTable();
        $this->_createEmbeddingsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%aiassistant_embeddings}}');
        $this->dropTableIfExists('{{%aiassistant_knowledge_chunks}}');
        $this->dropTableIfExists('{{%aiassistant_knowledge_files}}');
        $this->dropTableIfExists('{{%aiassistant_messages}}');
        $this->dropTableIfExists('{{%aiassistant_conversations}}');
        $this->dropTableIfExists('{{%aiassistant_page_rules}}');

        return true;
    }

    private function _createConversationsTable(): void
    {
        $this->createTable('{{%aiassistant_conversations}}', [
            'id' => $this->primaryKey(),
            'sessionId' => $this->string(36)->notNull(),
            'pageUrl' => $this->string(500)->null(),
            'ipAddress' => $this->string(45)->null(),
            'status' => $this->string(20)->notNull()->defaultValue('active'),
            'metadata' => $this->json()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%aiassistant_conversations}}', ['sessionId'], true);
        $this->createIndex(null, '{{%aiassistant_conversations}}', ['status']);
        $this->createIndex(null, '{{%aiassistant_conversations}}', ['dateCreated']);
    }

    private function _createMessagesTable(): void
    {
        $this->createTable('{{%aiassistant_messages}}', [
            'id' => $this->primaryKey(),
            'conversationId' => $this->integer()->notNull(),
            'role' => $this->string(20)->notNull(),
            'content' => $this->text()->null(),
            'toolCalls' => $this->json()->null(),
            'toolResults' => $this->json()->null(),
            'tokensUsed' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, '{{%aiassistant_messages}}', ['conversationId'], '{{%aiassistant_conversations}}', ['id'], 'CASCADE');
        $this->createIndex(null, '{{%aiassistant_messages}}', ['conversationId']);
        $this->createIndex(null, '{{%aiassistant_messages}}', ['role']);
    }

    private function _createKnowledgeFilesTable(): void
    {
        $this->createTable('{{%aiassistant_knowledge_files}}', [
            'id' => $this->primaryKey(),
            'filename' => $this->string(255)->notNull(),
            'originalName' => $this->string(255)->notNull(),
            'mimeType' => $this->string(100)->notNull(),
            'fileSize' => $this->integer()->notNull(),
            'status' => $this->string(20)->notNull()->defaultValue('processing'),
            'chunkCount' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%aiassistant_knowledge_files}}', ['status']);
    }

    private function _createKnowledgeChunksTable(): void
    {
        $this->createTable('{{%aiassistant_knowledge_chunks}}', [
            'id' => $this->primaryKey(),
            'fileId' => $this->integer()->notNull(),
            'content' => $this->text()->notNull(),
            'chunkIndex' => $this->integer()->notNull(),
            'tokenCount' => $this->integer()->notNull()->defaultValue(0),
            'metadata' => $this->json()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, '{{%aiassistant_knowledge_chunks}}', ['fileId'], '{{%aiassistant_knowledge_files}}', ['id'], 'CASCADE');
        $this->createIndex(null, '{{%aiassistant_knowledge_chunks}}', ['fileId']);

        $this->execute('ALTER TABLE {{%aiassistant_knowledge_chunks}} ADD FULLTEXT INDEX idx_chunk_content (content)');
    }

    private function _createEmbeddingsTable(): void
    {
        $this->createTable('{{%aiassistant_embeddings}}', [
            'id' => $this->primaryKey(),
            'chunkId' => $this->integer()->notNull(),
            'embedding' => 'LONGBLOB NOT NULL',
            'model' => $this->string(100)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, '{{%aiassistant_embeddings}}', ['chunkId'], '{{%aiassistant_knowledge_chunks}}', ['id'], 'CASCADE');
        $this->createIndex(null, '{{%aiassistant_embeddings}}', ['chunkId'], true);
    }

}
