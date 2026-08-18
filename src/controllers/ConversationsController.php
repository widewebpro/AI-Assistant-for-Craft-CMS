<?php

namespace widewebpro\aiassistant\controllers;

use Craft;
use craft\web\Controller;
use widewebpro\aiassistant\Plugin;
use widewebpro\aiassistant\records\ConversationRecord;
use widewebpro\aiassistant\records\MessageRecord;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class ConversationsController extends Controller
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
        $request = Craft::$app->getRequest();
        $status = $request->getQueryParam('status', '');
        $search = trim((string)$request->getQueryParam('search', ''));
        $page = (int)$request->getQueryParam('page', 1);
        $perPage = 20;

        $query = $this->_conversationQuery($status, $search);

        $total = $query->count();
        $conversations = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->all();

        return $this->renderTemplate('craft-ai-assistant/conversations/index', [
            'plugin' => Plugin::getInstance(),
            'conversations' => $conversations,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'status' => $status,
            'search' => $search,
        ]);
    }

    /** List query filtered by status and a search over sessionId / pageUrl / message content. */
    private function _conversationQuery(string $status, string $search): \yii\db\ActiveQuery
    {
        $query = ConversationRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($status) {
            $query->andWhere(['status' => $status]);
        }

        if ($search !== '') {
            $messageMatch = MessageRecord::find()
                ->where('[[conversationId]] = {{%aiassistant_conversations}}.[[id]]')
                ->andWhere(['like', 'content', $search]);

            $query->andWhere([
                'or',
                ['like', 'sessionId', $search],
                ['like', 'pageUrl', $search],
                ['exists', $messageMatch],
            ]);
        }

        return $query;
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

        $searchHits = [];
        foreach ($messages as $msg) {
            if ($msg->toolResults) {
                $hits = $this->_extractSearchHits($msg->toolResults);
                if (!empty($hits)) {
                    $searchHits[$msg->id] = $hits;
                }
            }
        }

        return $this->renderTemplate('craft-ai-assistant/conversations/view', [
            'plugin' => Plugin::getInstance(),
            'conversation' => $conversation,
            'messages' => $messages,
            'searchHits' => $searchHits,
        ]);
    }

    private function _extractSearchHits(mixed $raw): array
    {
        $hits = [];
        $this->_walkForHits($this->_deepJsonDecode($raw), $hits);
        return $hits;
    }

    private function _deepJsonDecode(mixed $value): mixed
    {
        for ($i = 0; $i < 6 && is_string($value); $i++) {
            $trimmed = ltrim($value);
            if ($trimmed === '' || !in_array($trimmed[0], ['[', '{', '"'], true)) {
                break;
            }
            $decoded = json_decode($value, true);
            if ($decoded === null) {
                break;
            }
            $value = $decoded;
        }
        return $value;
    }

    private function _walkForHits(mixed $node, array &$hits): void
    {
        if (is_string($node)) {
            $node = $this->_deepJsonDecode($node);
        }
        if (!is_array($node)) {
            return;
        }

        if (array_key_exists('relevance', $node) || (array_key_exists('score', $node) && (isset($node['source']) || isset($node['filename'])))) {
            $hits[] = [
                'source' => $node['source'] ?? $node['filename'] ?? '—',
                'score' => $node['relevance'] ?? $node['score'] ?? null,
            ];
            return;
        }

        foreach ($node as $child) {
            $this->_walkForHits($child, $hits);
        }
    }
}
