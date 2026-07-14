<?php

namespace bensomething\discontent\controllers;

use bensomething\discontent\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * The tool’s own settings page at /discontent/settings. Saving is handled by
 * the core plugins/save-plugin-settings action the form posts to.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('discontent/settings', [
            'settings' => Plugin::getInstance()->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }
}
