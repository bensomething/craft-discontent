<?php

namespace bensomething\contenttools;

use bensomething\contenttools\models\Settings;
use Craft;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Content Tools — enhanced, filterable Fields and Entry Types screens.
 *
 * The pages live canonically at /content-tools/<slug>. When the
 * overrideNativePages setting is on, Craft’s native Settings → Fields (and,
 * later, Entry Types) links redirect to them, so they become the de-facto
 * native pages while keeping clean URLs (which also keeps the nav subnav open).
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
     * The plugin’s gear (Settings → Plugins → Content Tools) opens the tool’s
     * own settings page.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('content-tools/settings'));
    }

    public function getCpNavItem(): ?array
    {
        if (!$this->getSettings()->showInMainNav) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('content-tools', 'Content Tools');
        $item['icon'] = 'hammer';
        // The shared prefix so the item stays selected (and its subnav open) on
        // every /content-tools/* subpage.
        $item['url'] = 'content-tools';
        $item['subnav'] = [
            'entry-types' => ['label' => Craft::t('content-tools', 'Entry Types'), 'url' => 'content-tools/entry-types', 'icon' => 'files'],
            'fields' => ['label' => Craft::t('content-tools', 'Fields'), 'url' => 'content-tools/fields', 'icon' => 'pen-to-square'],
            'settings' => ['label' => Craft::t('content-tools', 'Settings'), 'url' => 'content-tools/settings', 'icon' => 'gear'],
        ];

        return $item;
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                // Canonical pages — always available regardless of settings.
                $event->rules['content-tools'] = 'content-tools/fields/index';
                $event->rules['content-tools/fields'] = 'content-tools/fields/index';
                $event->rules['content-tools/entry-types'] = 'content-tools/entry-types/index';
                $event->rules['content-tools/settings'] = 'content-tools/settings/index';

                // Optionally redirect Craft’s native index pages to ours.
                // (Settings read here, not in init(), so it reflects saved
                // settings reliably.)
                if ($this->getSettings()->overrideNativePages) {
                    $event->rules['settings/fields'] = 'content-tools/fields/redirect';
                    // 'settings/entry-types' redirect added once that tool is built.
                }
            }
        );
    }
}
