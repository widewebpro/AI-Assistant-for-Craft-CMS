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

        return $this->renderTemplate('ai-agent/_index', [
            'plugin' => Plugin::getInstance(),
            'totalConversations' => $totalConversations,
            'activeConversations' => $activeConversations,
            'escalatedConversations' => $escalatedConversations,
            'totalMessages' => $totalMessages,
            'recentConversations' => $recentConversations,
        ]);
    }
}
