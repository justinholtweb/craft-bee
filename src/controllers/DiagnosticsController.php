<?php

namespace justinholtweb\bee\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\bee\errors\ApiException;
use justinholtweb\bee\jobs\BackfillOrders;
use justinholtweb\bee\Plugin;
use yii\web\Response;

class DiagnosticsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('bee-viewCatalog');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();
        $checks = $plugin->getDiagnostics()->run();

        return $this->renderTemplate('bee/diagnostics/_index', [
            'plugin' => $plugin,
            'checks' => $checks,
            'worst' => $plugin->getDiagnostics()->worstStatus($checks),
            'report' => $plugin->isPro() ? $plugin->getRecommendations()->report() : [],
            'commerce' => Plugin::commerceIsReady(),
            'canManage' => Craft::$app->getUser()->checkPermission('bee-manageCatalog'),
        ]);
    }

    /**
     * A one-shot credential check, from the settings screen.
     */
    public function actionTest(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('bee-viewCatalog');

        try {
            $ping = Plugin::getInstance()->getClient()->ping();
        } catch (ApiException $e) {
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('bee', 'Connected to {host} in {ms}ms.', ['host' => $ping['host'], 'ms' => $ping['ms']]),
        ]);
    }

    /**
     * Queue the historic order backfill. Pro.
     */
    public function actionBackfill(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('bee-manageCatalog');

        if (!Plugin::getInstance()->isPro()) {
            return $this->asFailure(Craft::t('bee', 'Backfilling historic orders is a Pro feature.'));
        }

        if (!Plugin::commerceIsReady()) {
            return $this->asFailure(Craft::t('bee', 'Commerce is not installed.'));
        }

        $since = Craft::$app->getRequest()->getBodyParam('since');

        Craft::$app->getQueue()->push(new BackfillOrders([
            'since' => is_string($since) && $since !== '' ? $since : null,
        ]));

        return $this->asSuccess(Craft::t('bee', 'Backfill queued. Watch the queue for progress.'));
    }
}
