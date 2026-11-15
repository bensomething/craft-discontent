<?php

declare(strict_types=1);

namespace bensomething\discontent\tests\Feature;

use bensomething\discontent\tests\TestCase;
use CraftCms\Cms\Cms;
use CraftCms\Cms\Database\Factories\SectionFactory;
use CraftCms\Cms\Database\Factories\UserFactory;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Section\Models\SectionSiteSettings;
use CraftCms\Cms\Section\Sections;
use Illuminate\Testing\TestResponse;
use Override;

/**
 * The Sections page and its rows. Fixtures: News, a versioned channel routed to
 * `news/{slug}`; About, an unversioned single with no URLs; and Docs, a versioned
 * structure routed to `docs/{slug}`.
 */
final class SectionsPageTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->section('News', SectionType::Channel, versioned: true, uriFormat: 'news/{slug}');
        $this->section('About', SectionType::Single, versioned: false, uriFormat: null);
        $this->section('Docs', SectionType::Structure, versioned: true, uriFormat: 'docs/{slug}');

        app(Sections::class)->refreshSections();

        $this->signIn();
    }

    public function test_it_opens_with_the_filters_that_apply(): void
    {
        $fields = $this->ui()['fields'];

        $this->assertSame(['', 'single', 'channel', 'structure'], $fields['type']);
        $this->assertSame(['', 'urls', 'no-urls', 'versioned', 'unversioned'], $fields['view']);
    }

    public function test_every_section_by_name(): void
    {
        $this->assertSame(['About', 'Docs', 'News'], $this->names());
    }

    public function test_filtering_by_type(): void
    {
        $this->assertSame(['About'], $this->names(['type' => 'single']));
        $this->assertSame(['News'], $this->names(['type' => 'channel']));
        $this->assertSame(['Docs'], $this->names(['type' => 'structure']));
    }

    public function test_the_views(): void
    {
        $this->assertSame(['Docs', 'News'], $this->names(['view' => 'urls']));
        $this->assertSame(['About'], $this->names(['view' => 'no-urls']));
        $this->assertSame(['Docs', 'News'], $this->names(['view' => 'versioned']));
        $this->assertSame(['About'], $this->names(['view' => 'unversioned']));
    }

    public function test_the_columns(): void
    {
        [$about, $docs] = $this->rows();

        $this->assertSame('Single', $about['type']);
        $this->assertSame('No URLs', $about['urls']);
        $this->assertSame('—', $about['versioning']);
        $this->assertMatchesRegularExpression('#<code [^>]*title="docs/\{slug\}"[^>]*>docs/\{slug\}</code>#', $docs['urls']['html']);
        $this->assertSame('check', $docs['versioning']['icon']);
    }

    public function test_search_matches_the_type_too(): void
    {
        $this->assertSame(['Docs'], $this->names(['search' => 'STRUCT']));
    }

    public function test_sorting_by_type(): void
    {
        $this->assertSame(['News', 'About', 'Docs'], $this->names([], [
            'sort' => [['field' => 'type', 'direction' => 'asc']],
        ]));
    }

    public function test_rows_open_in_a_slideout_and_delete_through_craft(): void
    {
        $row = $this->rows()[0];

        $this->assertStringContainsString('data-cp-url=', $row['name']['html']);
        $this->assertStringContainsString('/settings/sections/', $row['_deleteUrl']);
    }

    public function test_unknown_filters_are_ignored(): void
    {
        $this->assertSame(['About', 'Docs', 'News'], $this->names(['type' => 'nope', 'view' => 'nope']));
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

        $this->get('/admin/discontent/sections')->assertForbidden();
        $this->data()->assertForbidden();
    }

    private function section(string $name, SectionType $type, bool $versioned, ?string $uriFormat): void
    {
        $section = SectionFactory::new()->create([
            'name' => $name,
            'handle' => strtolower($name),
            'type' => $type,
            'enableVersioning' => $versioned,
        ]);

        SectionSiteSettings::query()->where('sectionId', $section->id)->update([
            'hasUrls' => $uriFormat !== null,
            'uriFormat' => $uriFormat,
        ]);
    }

    /**
     * @param  array<string, string>  $filters
     * @param  array<string, mixed>  $body
     */
    private function data(array $filters = [], array $body = []): TestResponse
    {
        return $this->postJson(route('discontent.sections.data', $filters), ['page' => 1, 'per_page' => 100, ...$body]);
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
        $html = (string) $this->get('/admin/discontent/sections')->assertOk()->getContent();
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
