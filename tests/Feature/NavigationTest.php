<?php

declare(strict_types=1);

namespace bensomething\discontent\tests\Feature;

use bensomething\discontent\Http\Middleware\RedirectNativePages;
use bensomething\discontent\Plugin;
use bensomething\discontent\tests\TestCase;
use CraftCms\Cms\Database\Factories\UserFactory;
use CraftCms\Cms\Edition;

/** The nav item, the pages' routes and who can reach them, and Replace Native Pages. */
final class NavigationTest extends TestCase
{
    public function test_there_is_no_nav_item_for_a_guest(): void
    {
        $this->assertNull(Plugin::getInstance()->getCpNavItem());
    }

    public function test_there_is_no_nav_item_for_a_non_admin(): void
    {
        Edition::set(Edition::Pro);
        $this->actingAs(UserFactory::new()->withPermissions(['accessCp', 'accessPlugin-discontent'])->create(), 'craft');

        $this->assertNull(Plugin::getInstance()->getCpNavItem());
    }

    public function test_the_nav_item_lists_the_pages_and_the_settings_screen(): void
    {
        $this->signIn();

        $item = Plugin::getInstance()->getCpNavItem();

        $this->assertNotNull($item);
        // Craft's own CP Navigation settings rename it; the plugin uses its own name.
        $this->assertSame('Discontent', $item->label);
        $this->assertSame(
            ['Sections', 'Entry Types', 'Fields', 'Settings'],
            array_map(fn ($child) => $child->label, (array) $item->subnav),
        );
        $this->assertSame(
            ['newspaper', 'files', 'pen-to-square', 'gear'],
            array_map(fn ($child) => $child->icon, (array) $item->subnav),
        );
        $this->assertStringEndsWith('/settings/plugins/discontent', (string) end($item->subnav)->href);
        // Its own ids: Settings' URL would otherwise give it Craft's Settings → Plugins →
        // Discontent id, and CP Navigation customizations of that item.
        $this->assertSame(
            ['nav-discontent-sections', 'nav-discontent-entry-types', 'nav-discontent-fields', 'nav-discontent-settings'],
            array_map(fn ($child) => $child->id, (array) $item->subnav),
        );
    }

    public function test_the_landing_url_opens_the_default_view(): void
    {
        $this->signIn();

        $this->get('/admin/discontent')->assertRedirectContains('/admin/discontent/fields');

        $this->setSettings(['defaultView' => 'sections']);
        $this->get('/admin/discontent')->assertRedirectContains('/admin/discontent/sections');
    }

    public function test_admins_can_open_every_page(): void
    {
        $this->signIn();

        foreach (['sections', 'entry-types', 'fields'] as $page) {
            $this->get("/admin/discontent/$page")->assertOk();
        }
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $this->get('/admin/discontent/fields')->assertRedirectContains('/admin/login');
    }

    public function test_non_admins_are_refused(): void
    {
        // Solo has one user, who counts as an admin.
        Edition::set(Edition::Pro);
        $user = UserFactory::new()->withPermissions(['accessCp', 'accessPlugin-discontent'])->create();
        $this->actingAs($user, 'craft');

        $this->get('/admin/discontent/fields')->assertForbidden();
    }

    public function test_the_native_pages_redirect_while_replace_native_pages_is_on(): void
    {
        $this->signIn();

        $this->get('/admin/settings/sections')->assertRedirectContains('/admin/discontent/sections');
        $this->get('/admin/settings/entry-types')->assertRedirectContains('/admin/discontent/entry-types');
        $this->get('/admin/settings/fields')->assertRedirectContains('/admin/discontent/fields');
    }

    public function test_the_native_pages_stay_while_it_is_off(): void
    {
        $this->signIn();
        $this->setSettings(['overrideNativePages' => false]);

        foreach (array_keys(RedirectNativePages::PAGES) as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_only_the_index_pages_redirect(): void
    {
        $this->signIn();

        // The edit screens under them, and slideout/JSON requests for the index itself.
        $this->get('/admin/settings/fields/new')->assertOk();
        $this->getJson('/admin/settings/fields', ['X-Craft-Container-Id' => 'x'])->assertOk();
    }

    public function test_the_redirect_runs_on_every_cp_route(): void
    {
        $this->assertContains(
            RedirectNativePages::class,
            app('router')->getMiddlewareGroups()['craft.cp'] ?? [],
        );
    }

    /** @param array<string, mixed> $values */
    private function setSettings(array $values): void
    {
        Plugin::getInstance()->setSettings($values);
    }
}
