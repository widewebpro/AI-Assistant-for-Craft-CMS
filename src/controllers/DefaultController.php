<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use widewebpro\aiagent\records\ConversationRecord;
use widewebpro\aiagent\records\MessageRecord;
use yii\web\Response;

class DefaultController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('aiAgent:viewConversations');
        return true;
    }

    public function actionIndex(): Response
    {
        $totalConversations = ConversationRecord::find()->count();
        $activeConversations = ConversationRecord::find()->where(['status' => 'active'])->count();
        $escalatedConversations = ConversationRecord::find()->where(['status' => 'escalated'])->count();
        $totalMessages = MessageRecord::find()->count();

        $recentConversations = ConversationRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(10)
            ->all();

        $embeddingModel = Plugin::getInstance()->getSettings()->embeddingModel;
        $totalChunks = (int)(new \yii\db\Query())->from('{{%aiagent_knowledge_chunks}}')->count();
        $vectorCount = (int)(new \yii\db\Query())
            ->from('{{%aiagent_embeddings}}')
            ->where(['model' => $embeddingModel])
            ->count();

        return $this->renderTemplate('ai-agent/_index', [
            'plugin' => Plugin::getInstance(),
            'totalConversations' => $totalConversations,
            'activeConversations' => $activeConversations,
            'escalatedConversations' => $escalatedConversations,
            'totalMessages' => $totalMessages,
            'recentConversations' => $recentConversations,
            'vectorCount' => $vectorCount,
            'totalChunks' => $totalChunks,
            'embeddingModel' => $embeddingModel,
        ]);
    }
}
