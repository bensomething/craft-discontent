<?php

namespace bensomething\contenttools;

use bensomething\contenttools\models\Settings;
use Craft;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Content Tools — replaces Craft’s Settings → Fields (and, later, Entry Types)
 * index pages with enhanced versions that add field-type and “unused” filtering.
 *
 * The native index routes are template-routed by Craft, so we override just the
 * index rule; the New/Edit screens remain core and redirect back to our page.
 *
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    // Craft only surfaces a plugin nav item when hasCpSection is true *and*
    // getCpNavItem() returns non-null — so the item appears only when the
    // showInMainNav setting is on.
    public bool $hasCpSection = true;

    public function init(): void
    {
        parent::init();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpRoutes();
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * The plugin’s gear (Settings → Plugins → Content Tools) shows the plugin’s
     * own settings — just the “show in main nav” toggle. The tools themselves
     * live on the native Settings → Fields / Entry Types pages.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->controller->renderTemplate('content-tools/settings', [
            'settings' => $this->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function getCpNavItem(): ?array
    {
        if (!$this->getSettings()->showInMainNav) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('content-tools', 'Content Tools');
        $item['icon'] = 'hammer';
        $item['url'] = 'settings/fields';
        $item['subnav'] = [
            'entry-types' => ['label' => Craft::t('content-tools', 'Entry Types'), 'url' => 'settings/entry-types', 'icon' => 'files'],
            'fields' => ['label' => Craft::t('content-tools', 'Fields'), 'url' => 'settings/fields', 'icon' => 'pen-to-square'],
            'settings' => ['label' => Craft::t('content-tools', 'Settings'), 'url' => 'settings/plugins/content-tools', 'icon' => 'gear'],
        ];

        return $item;
    }

    /**
     * Overrides Craft’s template-routed Settings → Fields index with our
     * controller. The New/Edit field screens keep their own core rules.
     */
    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['settings/fields'] = 'content-tools/fields/index';
                // 'settings/entry-types' is hijacked once the Entry Types tool
                // is built — until then the native page stays in place.
            }
        );
    }
}
