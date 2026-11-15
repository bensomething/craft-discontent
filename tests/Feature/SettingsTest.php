<?php

declare(strict_types=1);

namespace bensomething\discontent\tests\Feature;

use bensomething\discontent\Settings;
use bensomething\discontent\tests\TestCase;

/** The settings model on its own: defaults, validation and the name fallback. */
final class SettingsTest extends TestCase
{
    /** @param array<string, mixed> $values */
    private function make(array $values): Settings
    {
        $settings = new Settings;
        $settings->setAttributes($values);

        return $settings;
    }

    public function test_the_defaults_match_1x_and_are_valid(): void
    {
        $settings = new Settings;

        $this->assertTrue($settings->validate());
        $this->assertSame('Discontent', $settings->pluginName);
        $this->assertSame('fields', $settings->defaultView);
        $this->assertTrue($settings->overrideNativePages);
        $this->assertFalse($settings->showInMainNav);
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

    public function test_a_blank_name_falls_back_to_discontent(): void
    {
        foreach (['', '   ', null] as $blank) {
            $settings = $this->make(['pluginName' => $blank]);

            $this->assertSame('', $settings->pluginName);
            $this->assertSame('Discontent', $settings->getName());
            $this->assertTrue($settings->validate());
        }
    }

    public function test_the_name_is_trimmed(): void
    {
        $this->assertSame('Tidy', $this->make(['pluginName' => '  Tidy '])->getName());
    }
}
