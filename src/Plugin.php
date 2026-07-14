<?php

namespace bensomething\discontent;

use bensomething\discontent\models\Settings;
use Craft;
use craft\base\Model;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\UrlHelper;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Discontent — enhanced, filterable Fields and Entry Types screens.
 *
 * The pages live canonically at /discontent/<slug>. When the
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

        // Apply the admin-defined public-facing name.
        $this->name = $this->getSettings()->getName();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->registerCpRoutes();
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * The plugin’s gear (Settings → Plugins → Discontent) opens the tool’s
     * own settings page.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('discontent/settings'));
    }

    public function getCpNavItem(): ?array
    {
        if (!$this->getSettings()->showInMainNav) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = $this->name;
        $item['icon'] = 'hammer';
        // The shared prefix so the item stays selected (and its subnav open) on
        // every /discontent/* subpage.
        $item['url'] = 'discontent';
        $item['subnav'] = [
            'entry-types' => ['label' => Craft::t('discontent', 'Entry Types'), 'url' => 'discontent/entry-types', 'icon' => 'files'],
            'fields' => ['label' => Craft::t('discontent', 'Fields'), 'url' => 'discontent/fields', 'icon' => 'pen-to-square'],
            'settings' => ['label' => Craft::t('discontent', 'Settings'), 'url' => 'discontent/settings', 'icon' => 'gear'],
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
                // The bare prefix redirects to the configured default view so
                // the landing URL is a real subpage (and highlights its subnav).
                $event->rules['discontent'] = 'discontent/default/index';
                $event->rules['discontent/fields'] = 'discontent/fields/index';
                $event->rules['discontent/entry-types'] = 'discontent/entry-types/index';
                $event->rules['discontent/settings'] = 'discontent/settings/index';

                // Optionally redirect Craft’s native index pages to ours.
                // (Settings read here, not in init(), so it reflects saved
                // settings reliably.)
                if ($this->getSettings()->overrideNativePages) {
                    $event->rules['settings/fields'] = 'discontent/fields/redirect';
                    $event->rules['settings/entry-types'] = 'discontent/entry-types/redirect';
                }
            }
        );
    }
}
