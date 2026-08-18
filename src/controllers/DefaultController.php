<?php

namespace widewebpro\aiassistant\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiassistant\Plugin;
use widewebpro\aiassistant\records\ConversationRecord;
use widewebpro\aiassistant\records\MessageRecord;
use yii\web\Response;

class DefaultController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('aiAssistant:viewConversations');
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
        $totalChunks = (int)(new \yii\db\Query())->from('{{%aiassistant_knowledge_chunks}}')->count();
        $vectorCount = (int)(new \yii\db\Query())
            ->from('{{%aiassistant_embeddings}}')
            ->where(['model' => $embeddingModel])
            ->count();

        return $this->renderTemplate('craft-ai-assistant/_index', [
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
