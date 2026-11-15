<?php

declare(strict_types=1);

namespace bensomething\discontent\tests\Feature;

use bensomething\discontent\Plugin;
use bensomething\discontent\Settings;
use bensomething\discontent\tests\TestCase;

/** The settings model on its own: defaults, validation, and 1.x settings carrying over. */
final class SettingsTest extends TestCase
{
    /** @param array<string, mixed> $values */
    private function make(array $values): Settings
    {
        $settings = new Settings;
        $settings->setAttributes($values);

        return $settings;
    }

    public function test_the_defaults_are_valid(): void
    {
        $settings = new Settings;

        $this->assertTrue($settings->validate());
        $this->assertSame('fields', $settings->defaultView);
        $this->assertTrue($settings->overrideNativePages);
    }

    public function test_every_view_is_a_valid_default_view(): void
    {
        foreach (Settings::VIEWS as $view) {
            $this->assertTrue($this->make(['defaultView' => $view])->validate(), $view);
        }
    }

    public function test_an_unknown_default_view_is_not(): void
    {
        $settings = $this->make(['defaultView' => 'settings']);

        $this->assertFalse($settings->validate());
        $this->assertTrue($settings->errors()->has('defaultView'));
    }

    public function test_1x_settings_that_no_longer_exist_still_load(): void
    {
        // 1.x's Plugin Name and Show in Main Navigation, retired in 2.x for Craft's own
        // CP Navigation settings, are still in upgraded sites' project config.
        $plugin = Plugin::getInstance();
        $plugin->setSettings(['pluginName' => 'Schema', 'showInMainNav' => true, 'defaultView' => 'sections']);

        $this->assertSame('sections', $plugin->getSettings()->defaultView);
        $this->assertTrue($plugin->getSettings()->validate());
    }
}
