<?php

namespace justinholtweb\bee\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\bee\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class LogController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('bee-viewLog');

        return true;
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $filter = (string)$request->getParam('filter', '');
        $page = max(1, (int)$request->getParam('page', 1));
        $perPage = 50;

        $query = Plugin::getInstance()->getLog()->query();

        if ($filter === 'failures') {
            $query->where(['success' => false]);
        }

        $total = (int)(clone $query)->count();

        return $this->renderTemplate('bee/log/_index', [
            'plugin' => Plugin::getInstance(),
            'entries' => $query->offset(($page - 1) * $perPage)->limit($perPage)->all(),
            'filter' => $filter,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $perPage)),
            'total' => $total,
            'stats' => Plugin::getInstance()->getLog()->stats(),
        ]);
    }

    public function actionDetail(int $id): Response
    {
        $entry = Plugin::getInstance()->getLog()->get($id);

        if ($entry === null) {
            throw new NotFoundHttpException('No such log entry.');
        }

        return $this->renderTemplate('bee/log/_detail', [
            'plugin' => Plugin::getInstance(),
            'entry' => $entry,
        ]);
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $deleted = Plugin::getInstance()->getLog()->clear();

        return $this->asSuccess(Craft::t('bee', '{n, plural, =1{1 entry cleared} other{# entries cleared}}', ['n' => $deleted]));
    }
}
