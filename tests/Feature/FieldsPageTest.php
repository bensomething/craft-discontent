<?php

declare(strict_types=1);

namespace bensomething\discontent\tests\Feature;

use bensomething\discontent\tests\TestCase;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Factories\EntryTypeFactory;
use CraftCms\Cms\Database\Factories\FieldFactory;
use CraftCms\Cms\Database\Factories\FieldLayoutFactory;
use CraftCms\Cms\Database\Factories\UserFactory;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Entry\EntryTypes;
use CraftCms\Cms\Field\Dropdown;
use CraftCms\Cms\Field\Fields;
use CraftCms\Cms\Field\PlainText;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use Illuminate\Testing\TestResponse;
use Override;

/**
 * The Fields page and its rows. Fixtures: Body (plain text, searchable), used twice in
 * the Article entry type's layout, once as itself and once as "Intro" / `intro` (an
 * instance); Colour (a dropdown) used once as itself; and Summary (plain text), unused.
 */
final class FieldsPageTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $body = FieldFactory::new()->create(['name' => 'Body', 'handle' => 'body', 'type' => PlainText::class, 'searchable' => true]);
        $colour = FieldFactory::new()->create(['name' => 'Colour', 'handle' => 'colour', 'type' => Dropdown::class, 'searchable' => false]);
        FieldFactory::new()->create(['name' => 'Summary', 'handle' => 'summary', 'type' => PlainText::class, 'searchable' => false]);

        // The layout looks the fields up (to allow Body twice), so Craft has to know them.
        app(Fields::class)->refreshFields();

        $layout = FieldLayoutFactory::new()->withContentTab([
            new CustomField(config: ['fieldUid' => $body->uid]),
            new CustomField(config: ['fieldUid' => $body->uid, 'handle' => 'intro', 'label' => 'Intro']),
            new CustomField(config: ['fieldUid' => $colour->uid]),
        ]);

        EntryTypeFactory::new()->withFieldLayout($layout)->create(['name' => 'Article', 'handle' => 'article']);

        app(EntryTypes::class)->refreshEntryTypes();
        app(Fields::class)->refreshFields();

        $this->signIn();
    }

    public function test_it_opens_with_the_filters_that_apply(): void
    {
        $ui = $this->ui();

        $this->assertSame(['type', 'view'], array_keys($ui['fields']));
        $this->assertSame(['', Dropdown::class, PlainText::class], $ui['fields']['type']);
        $this->assertSame(['', 'unused', 'instances', 'keywords'], $ui['fields']['view']);
    }

    public function test_every_field_by_name(): void
    {
        $this->assertSame(['Body', 'Colour', 'Summary'], $this->names());
    }

    public function test_filtering_by_type(): void
    {
        $this->assertSame(['Body', 'Summary'], $this->names(['type' => PlainText::class]));
        $this->assertSame(['Colour'], $this->names(['type' => Dropdown::class]));
    }

    public function test_the_views(): void
    {
        $this->assertSame(['Summary'], $this->names(['view' => 'unused']));
        // With Body's instance nested under it.
        $this->assertSame(['Body', 'Intro'], $this->names(['view' => 'instances']));
        $this->assertSame(['Body'], $this->names(['view' => 'keywords']));
    }

    public function test_the_instances_view_nests_each_instance_under_its_field(): void
    {
        $rows = $this->rows(['view' => 'instances']);

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Intro', $rows[1]['name']['html']);
        $this->assertStringContainsString('/settings/entry-types/', $rows[1]['name']['html']);
        $this->assertSame('Instance', $rows[1]['type']);
        $this->assertFalse($rows[1]['_deletable']);
    }

    public function test_a_field_with_instances_says_so_in_every_view(): void
    {
        $this->assertStringContainsString('+ 1 instance', $this->rows()[0]['name']['html']);
    }

    public function test_a_field_in_one_layout_names_it_and_an_unused_one_says_so(): void
    {
        [$body, $colour, $summary] = $this->rows();

        $this->assertStringContainsString('Article', $colour['usages']['html']);
        $this->assertSame('No usages', $summary['usages']);
        // Body is in one layout twice: still one layout, so it's named.
        $this->assertStringContainsString('Article', $body['usages']['html']);
    }

    public function test_search_finds_a_field_by_an_instance_handle_or_its_type(): void
    {
        $this->assertSame(['Body'], $this->names([], ['search' => 'INTRO']));
        $this->assertSame(['Colour'], $this->names([], ['search' => 'dropdown']));
    }

    public function test_sorting_by_type(): void
    {
        $this->assertSame(['Colour', 'Body', 'Summary'], $this->names([], [
            'sort' => [['field' => 'type', 'direction' => 'asc']],
        ]));
    }

    public function test_rows_open_in_a_slideout_and_delete_through_craft(): void
    {
        $row = $this->rows()[0];

        $this->assertStringContainsString('data-cp-url=', $row['name']['html']);
        $this->assertStringContainsString('/settings/fields/', $row['_deleteUrl']);
    }

    public function test_an_unknown_type_is_ignored(): void
    {
        $this->assertSame(['Body', 'Colour', 'Summary'], $this->names(['type' => 'App\\Nope']));
    }

    public function test_nothing_is_deletable_or_creatable_where_admin_changes_are_off(): void
    {
        Cms::config()->allowAdminChanges(false);

        $this->assertArrayNotHasKey('_deleteUrl', $this->rows()[0]);
        $this->assertNull($this->ui()['createUrl']);
    }

    public function test_non_admins_are_refused(): void
    {
        Edition::set(Edition::Pro);
        $this->actingAs(UserFactory::new()->withPermissions(['accessCp'])->create(), 'craft');

        $this->get('/admin/discontent/fields')->assertForbidden();
        $this->data()->assertForbidden();
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $body
     */
    private function data(array $filters = [], array $body = []): TestResponse
    {
        return $this->postJson(route('discontent.fields.data', $filters), ['page' => 1, 'per_page' => 100, ...$body]);
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function rows(array $filters = [], array $body = []): array
    {
        return $this->data($filters, $body)->assertOk()->json('data');
    }

    /**
     * The field names, read from the rows' links.
     *
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function names(array $filters = [], array $body = []): array
    {
        return array_map(function (array $row): string {
            preg_match('/<a [^>]*>([^<]+)<\/a>/', $row['name']['html'], $m);

            return html_entity_decode($m[1] ?? '');
        }, $this->rows($filters, $body));
    }

    /**
     * The page's filter fields (path => option values) and its table's create URL.
     *
     * @return array{fields: array<string, list<string>>, createUrl: ?string}
     */
    private function ui(): array
    {
        $html = (string) $this->get('/admin/discontent/fields')->assertOk()->getContent();
        preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $nodes = json_decode($m[1], true)['props']['ui']['nodes'];

        $ui = ['fields' => [], 'createUrl' => null];

        foreach ($nodes as $node) {
            if (isset($node['control']['path'])) {
                $ui['fields'][end($node['control']['path'])] = array_column($node['control']['props']['options'] ?? [], 'value');
            } elseif (isset($node['props']['dataUrl'])) {
                $ui['createUrl'] = $node['props']['createUrl'] ?? null;
            }
        }

        return $ui;
    }
}
