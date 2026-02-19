<?php

namespace widewebpro\aiagent\migrations;

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
        $this->_createPageRulesTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%aiagent_embeddings}}');
        $this->dropTableIfExists('{{%aiagent_knowledge_chunks}}');
        $this->dropTableIfExists('{{%aiagent_knowledge_files}}');
        $this->dropTableIfExists('{{%aiagent_messages}}');
        $this->dropTableIfExists('{{%aiagent_conversations}}');
        $this->dropTableIfExists('{{%aiagent_page_rules}}');

        return true;
    }

    private function _createConversationsTable(): void
    {
        $this->createTable('{{%aiagent_conversations}}', [
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

        $this->createIndex(null, '{{%aiagent_conversations}}', ['sessionId'], true);
        $this->createIndex(null, '{{%aiagent_conversations}}', ['status']);
        $this->createIndex(null, '{{%aiagent_conversations}}', ['dateCreated']);
    }

    private function _createMessagesTable(): void
    {
        $this->createTable('{{%aiagent_messages}}', [
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

        $this->addForeignKey(null, '{{%aiagent_messages}}', ['conversationId'], '{{%aiagent_conversations}}', ['id'], 'CASCADE');
        $this->createIndex(null, '{{%aiagent_messages}}', ['conversationId']);
        $this->createIndex(null, '{{%aiagent_messages}}', ['role']);
    }

    private function _createKnowledgeFilesTable(): void
    {
        $this->createTable('{{%aiagent_knowledge_files}}', [
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

        $this->createIndex(null, '{{%aiagent_knowledge_files}}', ['status']);
    }

    private function _createKnowledgeChunksTable(): void
    {
        $this->createTable('{{%aiagent_knowledge_chunks}}', [
            'id' => $this->primaryKey(),
            'fileId' => $this->integer()->notNull(),
            'content' => $this->text()->notNull(),
            'chunkIndex' => $this->integer()->notNull(),
            'tokenCount' => $this->integer()->notNull()->defaultValue(0),
            'metadata' => $this->json()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, '{{%aiagent_knowledge_chunks}}', ['fileId'], '{{%aiagent_knowledge_files}}', ['id'], 'CASCADE');
        $this->createIndex(null, '{{%aiagent_knowledge_chunks}}', ['fileId']);

        $this->execute('ALTER TABLE {{%aiagent_knowledge_chunks}} ADD FULLTEXT INDEX idx_chunk_content (content)');
    }

    private function _createEmbeddingsTable(): void
    {
        $this->createTable('{{%aiagent_embeddings}}', [
            'id' => $this->primaryKey(),
            'chunkId' => $this->integer()->notNull(),
            'embedding' => 'LONGBLOB NOT NULL',
            'model' => $this->string(100)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, '{{%aiagent_embeddings}}', ['chunkId'], '{{%aiagent_knowledge_chunks}}', ['id'], 'CASCADE');
        $this->createIndex(null, '{{%aiagent_embeddings}}', ['chunkId'], true);
    }

    private function _createPageRulesTable(): void
    {
        $this->createTable('{{%aiagent_page_rules}}', [
            'id' => $this->primaryKey(),
            'pattern' => $this->string(500)->notNull(),
            'ruleType' => $this->string(20)->notNull()->defaultValue('include'),
            'sortOrder' => $this->integer()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%aiagent_page_rules}}', ['sortOrder']);
    }
}
