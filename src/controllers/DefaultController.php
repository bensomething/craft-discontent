<?php

namespace bensomething\contenttools\controllers;

use bensomething\contenttools\Plugin;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

/**
 * Sends the top-level /content-tools URL to the configured default view, so the
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
        $view = Plugin::getInstance()->getSettings()->defaultView === 'entry-types'
            ? 'entry-types'
            : 'fields';

        return $this->redirect(UrlHelper::cpUrl("content-tools/$view"));
    }
}
