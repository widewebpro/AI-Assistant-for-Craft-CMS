<?php

namespace craftcms\aiagent\controllers;

use Craft;
use craft\web\Controller;
use craftcms\aiagent\Plugin;
use craftcms\aiagent\records\ConversationRecord;
use craftcms\aiagent\records\MessageRecord;
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
