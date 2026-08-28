<?php

namespace justinholtweb\bee\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\bee\Plugin;
use yii\web\Response;

/**
 * Settings are saved through Craft's own plugin-settings screen; this exists for the actions the
 * settings template posts alongside it.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin(false);

        return true;
    }

    /**
     * Purge every guest identity cookie's Recombee counterpart for one user — the "forget me"
     * action a privacy request needs.
     */
    public function actionForgetUser(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $userId = (string)Craft::$app->getRequest()->getRequiredBodyParam('userId');

        if ($userId === '') {
            return $this->asJson(['success' => false]);
        }

        try {
            Plugin::getInstance()->getClient()->delete(
                'users/' . rawurlencode($userId),
                [],
                ['label' => 'forget ' . $userId],
            );
        } catch (\Throwable $e) {
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('bee', 'Deleted {id} and every interaction attached to it.', ['id' => $userId]),
        ]);
    }
}
