<?php

namespace widewebpro\aiagent\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiagent\Plugin;
use widewebpro\aiagent\records\ConversationRecord;
use widewebpro\aiagent\records\MessageRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ConversationsController extends Controller
{
    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $status = $request->getQueryParam('status', '');
        $search = $request->getQueryParam('search', '');
        $page = (int)$request->getQueryParam('page', 1);
        $perPage = 20;

        $query = ConversationRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($status) {
            $query->where(['status' => $status]);
        }

        $total = $query->count();
        $conversations = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return $this->renderTemplate('ai-agent/conversations/index', [
            'plugin' => Plugin::getInstance(),
            'conversations' => $conversations,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'status' => $status,
            'search' => $search,
        ]);
    }

    public function actionView(int $conversationId): Response
    {
        $conversation = ConversationRecord::findOne($conversationId);

        if (!$conversation) {
            throw new NotFoundHttpException('Conversation not found.');
        }

        $messages = MessageRecord::find()
            ->where(['conversationId' => $conversationId])
            ->orderBy(['dateCreated' => SORT_ASC])
            ->all();

        return $this->renderTemplate('ai-agent/conversations/view', [
            'plugin' => Plugin::getInstance(),
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }
}
