<?php

namespace bensomething\contenttools\controllers;

use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * The Entry Types tool. Filled in after the Fields tool is verified — for now
 * it renders a placeholder so the tab is reachable without erroring.
 */
class EntryTypesController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('content-tools/entry-types', [
            'title' => Craft::t('content-tools', 'Entry Types'),
            'selectedTab' => 'entry-types',
        ]);
    }
}
