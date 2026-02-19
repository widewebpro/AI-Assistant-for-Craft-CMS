<?php

namespace craftcms\aiagent\controllers;

use Craft;
use craft\web\Controller;
use craftcms\aiagent\Plugin;
use craftcms\aiagent\records\ConversationRecord;
use yii\db\Expression;
use yii\web\Response;

class PagesController extends Controller
{
    public function actionIndex(): Response
    {
        $rules = (new \yii\db\Query())
            ->from('{{%aiagent_page_rules}}')
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        return $this->renderTemplate('ai-agent/settings/pages', [
            'plugin' => Plugin::getInstance(),
            'rules' => $rules,
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $patterns = $request->getBodyParam('patterns', []);
        $ruleTypes = $request->getBodyParam('ruleTypes', []);

        Craft::$app->getDb()->createCommand()
            ->delete('{{%aiagent_page_rules}}')
            ->execute();

        $now = (new \DateTime())->format('Y-m-d H:i:s');

        foreach ($patterns as $i => $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) {
                continue;
            }

            Craft::$app->getDb()->createCommand()
                ->insert('{{%aiagent_page_rules}}', [
                    'pattern' => $pattern,
                    'ruleType' => $ruleTypes[$i] ?? 'include',
                    'sortOrder' => $i,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => \craft\helpers\StringHelper::UUID(),
                ])
                ->execute();
        }

        Craft::$app->getSession()->setNotice('Page rules saved.');
        return $this->redirectToPostedUrl();
    }
}
