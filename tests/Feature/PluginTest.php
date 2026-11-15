<?php

declare(strict_types=1);

namespace bensomething\discontent\tests\Feature;

use bensomething\discontent\Plugin;
use bensomething\discontent\tests\TestCase;
use CraftCms\Cms\Plugin\Plugins;

/** How Discontent boots, and that it comes and goes cleanly. */
final class PluginTest extends TestCase
{
    public function test_it_is_installed_and_native(): void
    {
        $plugin = Plugin::getInstance();

        $this->assertInstanceOf(Plugin::class, $plugin);
        $this->assertTrue(app(Plugins::class)->isPluginInstalled('discontent'));
        $this->assertTrue(app(Plugins::class)->isPluginEnabled('discontent'));
    }

    public function test_it_uninstalls_and_reinstalls_cleanly(): void
    {
        $plugins = app(Plugins::class);

        $this->assertTrue($plugins->uninstallPlugin('discontent'));
        $this->assertFalse($plugins->isPluginInstalled('discontent'));

        $this->assertTrue($plugins->installPlugin('discontent'));
        $this->assertTrue($plugins->isPluginInstalled('discontent'));
    }
}
