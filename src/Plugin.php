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
 * Content Tools — adds field-type and “unused” filtering to admin-facing
 * Fields and Entry Types screens.
 *
 * The tool lives at Settings → Plugins → Content Tools (the plugin’s gear).
 * A main-nav shortcut can be switched on from the Settings tab.
 *
 * @method Settings getSettings()
 */
class Plugin extends \craft\base\Plugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

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
     * The plugin’s gear in Settings → Plugins opens the tool itself
     * (the Fields screen), not a bare settings form.
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('content-tools/fields'));
    }

    /**
     * No main-nav item by default — the tool is reached via the plugin gear.
     * Flipping the `showInMainNav` setting adds a nav item with a subnav.
     */
    public function getCpNavItem(): ?array
    {
        if (!$this->getSettings()->showInMainNav) {
            return null;
        }

        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('content-tools', 'Content Tools');
        $item['icon'] = 'hammer';
        $item['subnav'] = [
            'fields' => ['label' => Craft::t('content-tools', 'Fields'), 'url' => 'content-tools/fields'],
            'entry-types' => ['label' => Craft::t('content-tools', 'Entry Types'), 'url' => 'content-tools/entry-types'],
            'settings' => ['label' => Craft::t('content-tools', 'Settings'), 'url' => 'content-tools/settings'],
        ];

        return $item;
    }

    private function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['content-tools'] = 'content-tools/fields/index';
                $event->rules['content-tools/fields'] = 'content-tools/fields/index';
                $event->rules['content-tools/entry-types'] = 'content-tools/entry-types/index';
                $event->rules['content-tools/settings'] = 'content-tools/settings/index';
            }
        );
    }
}
