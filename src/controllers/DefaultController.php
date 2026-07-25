<?php

namespace bensomething\discontent\controllers;

use bensomething\discontent\Plugin;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

/**
 * Sends the top-level /discontent URL to the configured default view, so the
 * landing URL is a real subpage (and its subnav item highlights).
 */
class DefaultController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $view = match (Plugin::getInstance()->getSettings()->defaultView) {
            'sections' => 'sections',
            'entry-types' => 'entry-types',
            default => 'fields',
        };

        return $this->redirect(UrlHelper::cpUrl("discontent/$view"));
    }
}
