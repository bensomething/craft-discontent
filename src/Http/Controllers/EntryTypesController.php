<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Controllers;

use bensomething\discontent\Http\Controllers\Concerns\BuildsFilteredTables;
use bensomething\discontent\Plugin;
use bensomething\discontent\Support\EntryTypeUsages;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Cp\Components\CopyAttribute;
use CraftCms\Cms\Cp\Enums\TableAppearance;
use CraftCms\Cms\Cp\Html\ElementHtml;
use CraftCms\Cms\Cp\Html\PreviewHtml;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Field\Contracts\ElementContainerFieldInterface;
use CraftCms\Cms\Http\Requests\TableRequest;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Markdown;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\Ui\UiResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

use function CraftCms\Cms\t;

/**
 * The Entry Types page: Craft's Settings → Entry Types list, plus a usage filter
 * (sections, Matrix, CKEditor, …), a view filter (unused, renamed somewhere, or showing
 * the slug, status, post date or expiry date) and Slug / Status / Post Date / Expiry Date columns: whether authors see
 * each of those fields.
 *
 * The filters rebuild the page through {@see renderUi()} (see {@see BuildsFilteredTables}).
 * Rows come from {@see data()}, which filters, searches, sorts and pages on the server,
 * so instance rows stay nested under their entry type.
 */
readonly class EntryTypesController
{
    use BuildsFilteredTables;

    private const string USAGE = 'usage';

    private const string VIEW = 'view';

    /** Each shown-field view and its opposite, by the entry type property they test. */
    private const array SHOWN_FIELDS = [
        'slugs' => 'showSlugField',
        'statuses' => 'showStatusField',
        'post-dates' => 'showPostDateField',
        'expiry-dates' => 'showExpiryDateField',
    ];

    private const array VIEWS = [
        'unused', 'instances',
        'slugs', 'no-slugs', 'statuses', 'no-statuses',
        'post-dates', 'no-post-dates', 'expiry-dates', 'no-expiry-dates',
    ];

    private bool $readOnly;

    public function __construct(
        private EntryTypeUsages $usages,
        GeneralConfig $generalConfig,
    ) {
        $this->readOnly = ! $generalConfig->allowAdminChanges;
    }

    /** The page, with any `?search=`, `?usage=` and `?view=` applied, so a filtered link can be shared. */
    public function index(Request $request): CpScreenResponse
    {
        $values = $this->filterValues($request->query());

        return new CpScreenResponse()
            ->title(t('Entry Types', category: 'discontent'))
            ->addCrumb((string) Plugin::getInstance()->name, 'discontent')
            ->ui($this->ui($values), new UiContext(values: $values, refreshable: true), [
                'refreshUrl' => route('discontent.entry-types.render-ui'),
            ]);
    }

    /** Rebuilds the page as a filter changes. */
    public function renderUi(Request $request, UiResolver $resolver): JsonResponse
    {
        $values = $this->filterValues($request->array('values'));

        return new JsonResponse([
            'ui' => $resolver->resolve($this->ui($values), new UiContext(values: $values, refreshable: true)),
        ]);
    }

    /** One page of rows, for the table. Search and filters arrive in the query string, paging and sorting posted. */
    public function data(TableRequest $request): JsonResponse
    {
        $filters = $this->filterValues($request->query());
        $search = mb_strtolower($filters['search']);
        $descending = $request->sortDir() === SORT_DESC;
        $byHandle = $request->orderBy() === 'handle';

        $entryTypes = EntryTypes::getAllEntryTypes()
            ->filter(fn (EntryType $type) => $this->matchesFilters($type, $filters))
            ->filter(fn (EntryType $type) => $search === '' || $this->matchesSearch($type, $search))
            ->sort(fn (EntryType $a, EntryType $b) => $byHandle
                ? strcasecmp((string) $a->handle, (string) $b->handle)
                : strcasecmp($a->getUiLabel(), $b->getUiLabel()))
            ->values();

        if ($descending) {
            $entryTypes = $entryTypes->reverse()->values();
        }

        [$paged, $pagination] = $this->paginate($entryTypes, $request->page(), $request->limit());

        $rows = [];

        foreach ($paged as $entryType) {
            $rows[] = $this->row($entryType);

            // Nested under their entry type in the instances view, so paging and sorting
            // stay at the entry-type level and the nesting rides along.
            if ($filters[self::VIEW] === 'instances') {
                foreach ($this->usages->instances((int) $entryType->id) as $i => $instance) {
                    $rows[] = $this->instanceRow($entryType, $instance, $i);
                }
            }
        }

        return new JsonResponse([
            'data' => Table::prepareRows($rows),
            'pagination' => $pagination,
        ]);
    }

    /** @param array<string, string> $values */
    private function ui(array $values): Ui
    {
        // Usage is only worth offering when entry types are used by more than one kind
        // of thing: "All" plus a single kind filters to everything that's used.
        $usageOptions = $this->usageOptions($values[self::USAGE]);

        $filters = [
            ...(count($usageOptions) > 2 ? $this->filterField(t('Usage', category: 'discontent'), self::USAGE, $usageOptions) : []),
            ...$this->filterField(t('Filter', category: 'discontent'), self::VIEW, $this->viewOptions($values[self::VIEW])),
        ];
        array_unshift($filters, $this->searchField(count($filters)));

        $table = Table::make($this->tableKey('entry-types', $values))
            ->appearance(TableAppearance::Flush)
            ->columns([
                ['key' => 'name', 'label' => t('Entry Type', category: 'discontent'), 'sortable' => true],
                ['key' => 'handle', 'label' => t('Handle', category: 'discontent'), 'sortable' => true],
                ['key' => 'usages', 'label' => t('Used by', category: 'discontent')],
                ['key' => 'slug', 'label' => t('Slug', category: 'discontent')],
                ['key' => 'status', 'label' => t('Status', category: 'discontent')],
                ['key' => 'postDate', 'label' => t('Post Date', category: 'discontent')],
                ['key' => 'expiryDate', 'label' => t('Expiry Date', category: 'discontent')],
            ])
            ->dataUrl(route('discontent.entry-types.data', array_filter($values)))
            // Craft's own column menu: each person shows, hides and reorders columns for
            // themselves, remembered across filter changes (the table's storage key is
            // its data URL's path, which filters don't change).
            ->toggleableColumns()
            ->emptyMessage(t('No entry types match these filters.', category: 'discontent'))
            ->unless($this->readOnly, fn (Table $table) => $table
                ->createAction(t('New entry type', category: 'discontent'), route('craft.cp.settings.entry-types.create'))
                ->createActionInPageHeader()
                ->deletable());

        return Ui::make([...$filters, $table]);
    }

    /**
     * The filters from a query string or a refresh, with anything unknown dropped.
     *
     * @param  array<array-key, mixed>  $input
     * @return array{search: string, usage: string, view: string}
     */
    private function filterValues(array $input): array
    {
        $kinds = [EntryTypeUsages::KIND_SECTION, EntryTypeUsages::KIND_MATRIX, EntryTypeUsages::KIND_CKEDITOR, EntryTypeUsages::KIND_FIELD];

        return [
            'search' => $this->searchValue($input),
            self::USAGE => $this->allowedValue($input, self::USAGE, $kinds),
            self::VIEW => $this->allowedValue($input, self::VIEW, self::VIEWS),
        ];
    }

    /**
     * Only the kinds of usage that exist here, plus whichever is selected, so a shared
     * link keeps its own filter.
     *
     * @return list<array{value: string, label: string}>
     */
    private function usageOptions(string $selected): array
    {
        $labels = [
            EntryTypeUsages::KIND_SECTION => t('Section entries', category: 'discontent'),
            EntryTypeUsages::KIND_MATRIX => t('Matrix entries', category: 'discontent'),
            EntryTypeUsages::KIND_CKEDITOR => t('CKEditor entries', category: 'discontent'),
            EntryTypeUsages::KIND_FIELD => t('Other nested entries', category: 'discontent'),
        ];

        $kinds = $this->usages->kindsInUse();
        if ($selected !== '' && ! in_array($selected, $kinds, true)) {
            $kinds[] = $selected;
        }

        return [
            ['value' => '', 'label' => t('All entry types', category: 'discontent')],
            ...array_map(fn (string $kind) => ['value' => $kind, 'label' => $labels[$kind]], $kinds),
        ];
    }

    /**
     * Unused and Has instances where they have something to show, then a Has / No pair
     * per shown field (see {@see viewPair()}), plus whichever is selected.
     *
     * @return list<array{value: string, label: string, group?: string}>
     */
    private function viewOptions(string $selected): array
    {
        /** @var Collection<int, EntryType> $all */
        $all = EntryTypes::getAllEntryTypes();
        $total = $all->count();
        $count = fn (string $property) => $all->filter(fn (EntryType $type) => (bool) $type->$property)->count();

        $options = [['value' => '', 'label' => t('Show all', category: 'discontent')]];

        if ($selected === 'unused' || $all->contains(fn (EntryType $type) => ! $this->usages->isUsed((int) $type->id))) {
            $options[] = ['value' => 'unused', 'label' => t('Unused', category: 'discontent')];
        }

        if ($selected === 'instances' || $this->usages->hasInstances()) {
            $options[] = ['value' => 'instances', 'label' => t('Has instances', category: 'discontent')];
        }

        $pairs = [
            'slugs' => [t('Slug', category: 'discontent'), t('Has slug', category: 'discontent'), t('No slug', category: 'discontent')],
            'statuses' => [t('Status', category: 'discontent'), t('Has status', category: 'discontent'), t('No status', category: 'discontent')],
            'post-dates' => [t('Post Date', category: 'discontent'), t('Has post date', category: 'discontent'), t('No post date', category: 'discontent')],
            'expiry-dates' => [t('Expiry Date', category: 'discontent'), t('Has expiry date', category: 'discontent'), t('No expiry date', category: 'discontent')],
        ];

        foreach ($pairs as $view => [$group, $has, $no]) {
            array_push($options, ...$this->viewPair($group, [$view, $has], ["no-$view", $no], $count(self::SHOWN_FIELDS[$view]), $total, $selected));
        }

        return $options;
    }

    /** @param array{search: string, usage: string, view: string} $filters */
    private function matchesFilters(EntryType $type, array $filters): bool
    {
        $id = (int) $type->id;

        if ($filters[self::USAGE] !== '' && ! $this->usages->isUsedAs($id, $filters[self::USAGE])) {
            return false;
        }

        $view = $filters[self::VIEW];

        // A shown-field view, or its "no-" opposite.
        $negated = str_starts_with($view, 'no-');
        $shown = self::SHOWN_FIELDS[$negated ? substr($view, 3) : $view] ?? null;
        if ($shown !== null) {
            return (bool) $type->$shown !== $negated;
        }

        return match ($view) {
            'unused' => ! $this->usages->isUsed($id),
            'instances' => $this->usages->instances($id) !== [],
            default => true,
        };
    }

    /** Name and handle, or any instance's, so an entry type can be found by another name. */
    private function matchesSearch(EntryType $type, string $search): bool
    {
        $haystacks = [$type->getUiLabel(), (string) $type->handle];

        foreach ($this->usages->instances((int) $type->id) as $instance) {
            $haystacks[] = $instance['label'];
            $haystacks[] = $instance['handle'];
        }

        foreach ($haystacks as $haystack) {
            if (str_contains(mb_strtolower($haystack), $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether authors see the Slug, Status, Post Date and Expiry Date fields. An
     * instance shows what it inherits: renaming doesn't touch these.
     *
     * @return array<string, array{icon: string, label: string}|array{html: string}>
     */
    private function shownFields(EntryType $type): array
    {
        return [
            'slug' => $this->flag((bool) $type->showSlugField),
            'status' => $this->flag((bool) $type->showStatusField),
            'postDate' => $this->flag((bool) $type->showPostDateField),
            'expiryDate' => $this->flag((bool) $type->showExpiryDateField),
        ];
    }

    /** @return array<string, mixed> */
    private function row(EntryType $type): array
    {
        $url = (string) $type->getCpEditUrl();
        $instanceCount = count($this->usages->instances((int) $type->id));

        $name = Html::beginTag('div', ['class' => 'flex gap-1 items-center row-wrap']).
            app(ElementHtml::class)->chipHtml($type, [
                'labelHtml' => $this->slideoutLink($type->getUiLabel(), $url),
            ]);

        if ($type->description) {
            $name .= Html::tag('craft-info-icon', Html::decodeDoubles(
                Markdown::parse(Html::encodeInvalidTags(Html::encode($type->description)), 'gfm-comment'),
            ));
        }

        // Worth flagging in every view, not just the instances one.
        $name .= $this->instanceNote(
            $instanceCount,
            route('discontent.entry-types', ['search' => (string) $type->handle, self::VIEW => 'instances']),
        );

        $name .= Html::endTag('div');

        $usedBy = $this->usages->usages((int) $type->id);

        return [
            'id' => $type->id,
            'name' => ['html' => $name],
            'handle' => ['html' => CopyAttribute::make()->value((string) $type->handle)->toHtml()],
            'usages' => $usedBy === []
                ? $this->quiet(t('No usages', category: 'discontent'))
                : ['html' => app(PreviewHtml::class)->componentPreviewHtml($usedBy, ['hyperlink' => true])],
            ...$this->shownFields($type),
            ...($this->readOnly ? [] : [
                '_deleteUrl' => route('craft.cp.settings.entry-types.destroy', ['entryType' => $type->id]),
                '_deleteConfirmMessage' => t('Are you sure you want to delete “{name}” and all entries of that type?', [
                    'name' => $type->getUiLabel(),
                ], 'discontent'),
            ]),
        ];
    }

    /**
     * A usage that renames the entry type: the name it goes by there, linked to the
     * section or field that renamed it (where Craft edits the override), which a
     * double-click opens.
     *
     * @param  array{label: string, handle: string, owner: Section|ElementContainerFieldInterface}  $instance
     * @return array<string, mixed>
     */
    private function instanceRow(EntryType $type, array $instance, int $i): array
    {
        $owner = $instance['owner'];
        $ownerUrl = $owner instanceof Section
            ? route('craft.cp.settings.sections.edit', ['section' => $owner->id])
            : route('craft.cp.settings.fields.edit', ['fieldId' => $owner->id]);

        $name = $this->instanceName($this->slideoutLink($instance['label'], $ownerUrl));

        return [
            'id' => "$type->id-instance-$i",
            'name' => ['html' => $name],
            'handle' => ['html' => CopyAttribute::make()->value($instance['handle'])->toHtml()],
            'usages' => ['html' => app(PreviewHtml::class)->componentPreviewHtml([$owner], ['hyperlink' => true])],
            ...$this->shownFields($type),
            '_deletable' => false,
        ];
    }
}
