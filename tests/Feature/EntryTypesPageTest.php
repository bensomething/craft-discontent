<?php

declare(strict_types=1);

namespace bensomething\discontent\tests\Feature;

use bensomething\discontent\tests\TestCase;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Factories\EntryTypeFactory;
use CraftCms\Cms\Database\Factories\FieldFactory;
use CraftCms\Cms\Database\Factories\SectionFactory;
use CraftCms\Cms\Database\Factories\UserFactory;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Entry\EntryTypes;
use CraftCms\Cms\Field\Fields;
use CraftCms\Cms\Field\Matrix;
use Illuminate\Testing\TestResponse;
use Override;

/**
 * The Entry Types page and its rows. Fixtures: Article, used by the News section,
 * which renames it Story (an instance); Block, used by a Matrix field, hiding its
 * expiry date; and Orphan, used by nothing and hiding its slug, status and post date.
 */
final class EntryTypesPageTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $article = EntryTypeFactory::new()->create(['name' => 'Article', 'handle' => 'article']);
        $block = EntryTypeFactory::new()->create(['name' => 'Block', 'handle' => 'block', 'showExpiryDateField' => false]);
        EntryTypeFactory::new()->create([
            'name' => 'Orphan',
            'handle' => 'orphan',
            'showSlugField' => false,
            'showStatusField' => false,
            'showPostDateField' => false,
        ]);

        $section = SectionFactory::new()->create(['name' => 'News', 'handle' => 'news']);
        $section->entryTypes()->attach($article, ['sortOrder' => 1, 'name' => 'Story', 'handle' => 'story']);

        FieldFactory::new()->create([
            'name' => 'Blocks',
            'handle' => 'blocks',
            'type' => Matrix::class,
            'settings' => ['entryTypes' => [$block->uid]],
        ]);

        app(EntryTypes::class)->refreshEntryTypes();
        app(Fields::class)->refreshFields();

        $this->signIn();
    }

    public function test_it_opens_with_the_filters_that_apply(): void
    {
        $ui = $this->ui();

        $this->assertSame(['search', 'usage', 'view'], array_keys($ui['fields']));
        $this->assertSame(['', 'section', 'matrix'], $ui['fields']['usage']);
        $this->assertSame([
            '', 'unused', 'instances',
            'slugs', 'no-slugs', 'statuses', 'no-statuses',
            'post-dates', 'no-post-dates', 'expiry-dates', 'no-expiry-dates',
        ], $ui['fields']['view']);
        $this->assertStringContainsString('/discontent/entry-types/data', (string) $ui['dataUrl']);
    }

    public function test_a_shared_link_opens_filtered(): void
    {
        $ui = $this->ui('?view=unused');

        $this->assertStringContainsString('view=unused', (string) $ui['dataUrl']);
        $this->assertStringContainsString('unused', (string) $ui['uid']);
    }

    public function test_every_entry_type_by_name(): void
    {
        $this->assertSame(['Article', 'Block', 'Orphan'], $this->names());
    }

    public function test_filtering_by_usage(): void
    {
        $this->assertSame(['Article'], $this->names(['usage' => 'section']));
        $this->assertSame(['Block'], $this->names(['usage' => 'matrix']));
    }

    public function test_the_views(): void
    {
        $this->assertSame(['Orphan'], $this->names(['view' => 'unused']));
        $this->assertSame(['Article', 'Block'], $this->names(['view' => 'slugs']));
        $this->assertSame(['Article', 'Block'], $this->names(['view' => 'statuses']));
        $this->assertSame(['Article', 'Block'], $this->names(['view' => 'post-dates']));
        $this->assertSame(['Article', 'Orphan'], $this->names(['view' => 'expiry-dates']));
        $this->assertSame(['Orphan'], $this->names(['view' => 'no-slugs']));
        $this->assertSame(['Orphan'], $this->names(['view' => 'no-post-dates']));
        $this->assertSame(['Block'], $this->names(['view' => 'no-expiry-dates']));
    }

    public function test_the_instances_view_nests_each_instance_under_its_entry_type(): void
    {
        $rows = $this->rows(['view' => 'instances']);

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('Article', $rows[0]['name']['html']);
        $this->assertStringContainsString('Story', $rows[1]['name']['html']);
        $this->assertStringContainsString('/settings/sections/', $rows[1]['name']['html']);
        $this->assertFalse($rows[1]['_deletable']);
    }

    public function test_an_entry_type_with_instances_says_so_in_every_view(): void
    {
        $this->assertStringContainsString('+ 1 instance', $this->rows()[0]['name']['html']);
    }

    public function test_search_finds_an_entry_type_by_an_instance_name(): void
    {
        $this->assertSame(['Article'], $this->names(['search' => 'stor']));
        $this->assertSame(['Orphan'], $this->names(['search' => 'ORPH']));
    }

    public function test_sorting_by_handle_descending(): void
    {
        $this->assertSame(['Orphan', 'Block', 'Article'], $this->names([], [
            'sort' => [['field' => 'handle', 'direction' => 'desc']],
        ]));
    }

    public function test_paging(): void
    {
        $response = $this->data([], ['page' => 2, 'per_page' => 2]);

        $this->assertSame(['Orphan'], $this->namesFrom($response->json('data')));
        $this->assertSame(3, $response->json('pagination.total'));
        $this->assertSame(2, $response->json('pagination.last_page'));
    }

    public function test_rows_open_in_a_slideout_and_delete_through_craft(): void
    {
        $row = $this->rows()[0];

        $this->assertStringContainsString('data-cp-url=', $row['name']['html']);
        $this->assertStringContainsString('data-editable', $row['name']['html']);
        $this->assertStringContainsString('/settings/entry-types/', $row['_deleteUrl']);
    }

    public function test_the_shown_field_columns(): void
    {
        $rows = $this->rows();

        $this->assertSame(['slug', 'status', 'postDate', 'expiryDate'], array_values(array_intersect(
            array_keys($rows[0]),
            ['slug', 'status', 'postDate', 'expiryDate'],
        )));
        $this->assertSame('check', $rows[0]['slug']['icon']);
        $this->assertSame('—', $rows[2]['slug'], 'Orphan hides its slug');
        $this->assertSame('—', $rows[2]['status'], 'Orphan hides its status');
    }

    public function test_unknown_filters_are_ignored(): void
    {
        $this->assertSame(['Article', 'Block', 'Orphan'], $this->names(['usage' => 'nope', 'view' => 'nope']));
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

        $this->get('/admin/discontent/entry-types')->assertForbidden();
        $this->data()->assertForbidden();
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $body
     */
    private function data(array $filters = [], array $body = []): TestResponse
    {
        return $this->postJson(route('discontent.entry-types.data', $filters), [
            'page' => 1,
            'per_page' => 100,
            ...$body,
        ]);
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
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function names(array $filters = [], array $body = []): array
    {
        return $this->namesFrom($this->rows($filters, $body));
    }

    /**
     * The entry type names, read from the rows' chip links.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function namesFrom(array $rows): array
    {
        return array_map(function (array $row): string {
            preg_match('/<a [^>]*>([^<]+)<\/a>/', $row['name']['html'], $m);

            return html_entity_decode($m[1] ?? '');
        }, $rows);
    }

    /**
     * The page's filter fields (path => option values) and its table's props.
     *
     * @return array{fields: array<string, list<string>>, dataUrl: ?string, uid: ?string, createUrl: ?string}
     */
    private function ui(string $query = ''): array
    {
        $html = (string) $this->get('/admin/discontent/entry-types'.$query)->assertOk()->getContent();
        preg_match('/<script data-page="app"[^>]*>(.*?)<\/script>/s', $html, $m);
        $nodes = json_decode($m[1], true)['props']['ui']['nodes'];

        $ui = ['fields' => [], 'dataUrl' => null, 'uid' => null, 'createUrl' => null];

        foreach ($nodes as $node) {
            if (isset($node['control']['path'])) {
                $ui['fields'][end($node['control']['path'])] = array_column($node['control']['props']['options'] ?? [], 'value');
            } elseif (isset($node['props']['dataUrl'])) {
                $ui['dataUrl'] = $node['props']['dataUrl'];
                $ui['uid'] = $node['uid'] ?? null;
                $ui['createUrl'] = $node['props']['createUrl'] ?? null;
            }
        }

        return $ui;
    }
}
