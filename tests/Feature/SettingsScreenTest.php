<?php

declare(strict_types=1);

namespace bensomething\discontent\tests\Feature;

use bensomething\discontent\Plugin;
use bensomething\discontent\Settings;
use bensomething\discontent\tests\TestCase;
use CraftCms\Cms\Cms;
use CraftCms\Cms\ProjectConfig\ProjectConfig;

/** Settings → Plugins → Discontent, as an admin reaches it. */
final class SettingsScreenTest extends TestCase
{
    private const string URL = '/admin/settings/plugins/discontent';

    public function test_it_opens_with_default_view_hidden_while_the_nav_item_is_off(): void
    {
        $this->signIn();

        $fields = $this->fields();

        $this->assertSame(['pluginName', 'overrideNativePages', 'showInMainNav'], array_keys($fields));
    }

    public function test_default_view_shows_once_the_nav_item_is_on(): void
    {
        $this->signIn();
        $this->setSettings(['showInMainNav' => true]);

        $this->assertArrayHasKey('defaultView', $this->fields());
    }

    public function test_it_saves(): void
    {
        $this->signIn();

        $this->postJson(self::URL, ['settings' => [
            'pluginName' => 'Schema',
            'overrideNativePages' => '',
            'showInMainNav' => '1',
            'defaultView' => 'sections',
        ]])->assertOk();

        $settings = Plugin::getInstance()->getSettings();

        $this->assertSame('Schema', $settings->pluginName);
        $this->assertFalse($settings->overrideNativePages);
        $this->assertTrue($settings->showInMainNav);
        $this->assertSame('sections', $settings->defaultView);
        $this->assertSame('sections', app(ProjectConfig::class)->get('plugins.discontent.settings.defaultView'));
    }

    public function test_an_emptied_name_saves_as_blank(): void
    {
        $this->signIn();
        $this->setSettings(['pluginName' => 'Schema']);

        $this->postJson(self::URL, ['settings' => ['pluginName' => '']])->assertOk();

        $this->assertSame('Discontent', Plugin::getInstance()->getSettings()->getName());
    }

    public function test_bad_values_are_refused_and_not_saved(): void
    {
        $this->signIn();

        $this->postJson(self::URL, ['settings' => ['defaultView' => 'nope']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.defaultView']);

        $this->assertNotSame('nope', app(ProjectConfig::class)->get('plugins.discontent.settings.defaultView'));
    }

    public function test_it_opens_read_only_where_admin_changes_are_off(): void
    {
        $this->signIn();
        Cms::config()->allowAdminChanges(false);

        foreach ($this->fields() as $setting => $field) {
            $this->assertSame('readOnly', $field['mode'], $setting);
        }

        $this->postJson(self::URL, ['settings' => ['showInMainNav' => '1']])->assertForbidden();
    }

    public function test_settings_from_the_config_file_are_read_only_and_say_why(): void
    {
        $this->signIn();
        config(['craft.discontent' => ['overrideNativePages' => false]]);

        $fields = $this->fields();

        $this->assertSame('readOnly', $fields['overrideNativePages']['mode']);
        $this->assertStringContainsString('config/craft/discontent.php', (string) $fields['overrideNativePages']['warning']);

        $this->assertNotSame('readOnly', $fields['pluginName']['mode']);
        $this->assertNull($fields['pluginName']['warning']);
    }

    public function test_the_config_sample_is_where_craft_publishes_from(): void
    {
        $sample = dirname(__DIR__, 2).'/config/discontent.php';

        $this->assertFileExists($sample);
        $this->assertIsArray(require $sample);
    }

    public function test_the_chosen_name_names_the_plugin(): void
    {
        $this->setSettings(['pluginName' => 'Schema']);
        Plugin::getInstance()->boot(app('router'));

        $this->assertSame('Schema', Plugin::getInstance()->name);
    }

    /** @param array<string, mixed> $values */
    private function setSettings(array $values): Settings
    {
        $plugin = Plugin::getInstance();
        $plugin->setSettings($values);

        return $plugin->getSettings();
    }

    /**
     * Each field on the settings screen, by setting, with its control's mode and the
     * field's warning.
     *
     * @return array<string, array{mode: ?string, warning: ?string}>
     */
    private function fields(): array
    {
        $html = (string) $this->get(self::URL)->assertOk()->getContent();
        preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $nodes = json_decode($m[1], true)['props']['ui']['nodes'];

        $fields = [];

        foreach ($nodes as $node) {
            if (isset($node['control']['path'])) {
                $fields[end($node['control']['path'])] = [
                    'mode' => $node['control']['mode'] ?? null,
                    'warning' => $node['props']['warning'] ?? null,
                ];
            }
        }

        return $fields;
    }
}
