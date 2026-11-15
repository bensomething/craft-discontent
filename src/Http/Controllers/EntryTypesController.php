<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Controllers;

use bensomething\discontent\Plugin;
use bensomething\discontent\Support\EntryTypeUsages;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Cp\Components\CopyAttribute;
use CraftCms\Cms\Cp\Components\Icon;
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
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Enums\FieldWidth;
use CraftCms\Cms\Ui\Nodes\Field;
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
 * (sections, Matrix, CKEditor, …), a view filter (unused, renamed somewhere, slug and
 * status shown) and Show Slug / Show Status columns.
 *
 * The filters are reactive `Ui` controls: changing one rebuilds the page through
 * {@see renderUi()}, whose table carries the filters in its `dataUrl` *and* its uid. The
 * uid is what makes the table remount and fetch from the new URL; a table that keeps its
 * uid keeps its rows. Rows come from {@see data()}, which filters, searches, sorts and
 * pages on the server, so instance rows stay nested under their entry type.
 */
readonly class EntryTypesController
{
    private const string USAGE = 'usage';

    private const string VIEW = 'view';

    private const array VIEWS = ['unused', 'instances', 'slugs', 'statuses'];

    private bool $readOnly;

    public function __construct(
        private EntryTypeUsages $usages,
        GeneralConfig $generalConfig,
    ) {
        $this->readOnly = ! $generalConfig->allowAdminChanges;
    }

    /** The page, with any `?usage=` and `?view=` applied, so a filtered link can be shared. */
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

    /** One page of rows, for the table. Filters arrive in the query string, the rest posted. */
    public function data(TableRequest $request): JsonResponse
    {
        $filters = $this->filterValues($request->query());
        $search = mb_strtolower(trim((string) $request->search()));
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

        $total = $entryTypes->count();
        $perPage = $request->limit();
        $page = min($request->page(), max(1, (int) ceil($total / $perPage)));
        $paged = $entryTypes->slice(($page - 1) * $perPage, $perPage);

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
            'pagination' => $this->pagination($total, $page, $perPage),
        ]);
    }

    /** @param array<string, string> $values */
    private function ui(array $values): Ui
    {
        $filters = [];

        $usageOptions = $this->usageOptions($values[self::USAGE]);
        if (count($usageOptions) > 2) {
            $filters[] = Field::make(t('Usage', category: 'discontent'), Choice::make(self::USAGE)
                ->options($usageOptions)
                ->withoutPlaceholder()
                ->reactive())
                ->width(FieldWidth::Half);
        }

        $viewOptions = $this->viewOptions($values[self::VIEW]);
        if (count($viewOptions) > 1) {
            $filters[] = Field::make(t('View', category: 'discontent'), Choice::make(self::VIEW)
                ->options($viewOptions)
                ->withoutPlaceholder()
                ->reactive())
                ->width(FieldWidth::Half);
        }

        $key = implode('-', array_filter([$values[self::USAGE], $values[self::VIEW]])) ?: 'all';

        $table = Table::make("discontent-entry-types-$key")
            ->columns([
                ['key' => 'name', 'label' => t('Entry Type', category: 'discontent'), 'sortable' => true],
                ['key' => 'handle', 'label' => t('Handle', category: 'discontent'), 'sortable' => true],
                ['key' => 'usages', 'label' => t('Used by', category: 'discontent')],
                ['key' => 'slug', 'label' => t('Show Slug', category: 'discontent')],
                ['key' => 'status', 'label' => t('Show Status', category: 'discontent')],
            ])
            ->dataUrl(route('discontent.entry-types.data', array_filter($values)))
            ->searchable()
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
     * @return array{usage: string, view: string}
     */
    private function filterValues(array $input): array
    {
        $usage = is_string($input[self::USAGE] ?? null) ? $input[self::USAGE] : '';
        $view = is_string($input[self::VIEW] ?? null) ? $input[self::VIEW] : '';

        $kinds = [EntryTypeUsages::KIND_SECTION, EntryTypeUsages::KIND_MATRIX, EntryTypeUsages::KIND_CKEDITOR, EntryTypeUsages::KIND_FIELD];

        return [
            self::USAGE => in_array($usage, $kinds, true) ? $usage : '',
            self::VIEW => in_array($view, self::VIEWS, true) ? $view : '',
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
     * Each view only where it splits the list (filtering to all of them says nothing),
     * plus whichever is selected.
     *
     * @return list<array{value: string, label: string}>
     */
    private function viewOptions(string $selected): array
    {
        /** @var Collection<int, EntryType> $all */
        $all = EntryTypes::getAllEntryTypes();
        $total = $all->count();
        $splits = fn (int $count) => $count > 0 && $count < $total;

        $available = [
            'unused' => $all->contains(fn (EntryType $type) => ! $this->usages->isUsed((int) $type->id)),
            'instances' => $this->usages->hasInstances(),
            'slugs' => $splits($all->filter(fn (EntryType $type) => (bool) $type->showSlugField)->count()),
            'statuses' => $splits($all->filter(fn (EntryType $type) => (bool) $type->showStatusField)->count()),
        ];

        $labels = [
            'unused' => t('Unused', category: 'discontent'),
            'instances' => t('Has instances', category: 'discontent'),
            'slugs' => t('Has slugs', category: 'discontent'),
            'statuses' => t('Has statuses', category: 'discontent'),
        ];

        $options = [['value' => '', 'label' => t('Show all', category: 'discontent')]];

        foreach ($labels as $view => $label) {
            if ($available[$view] || $selected === $view) {
                $options[] = ['value' => $view, 'label' => $label];
            }
        }

        return $options;
    }

    /** @param array{usage: string, view: string} $filters */
    private function matchesFilters(EntryType $type, array $filters): bool
    {
        $id = (int) $type->id;

        if ($filters[self::USAGE] !== '' && ! $this->usages->isUsedAs($id, $filters[self::USAGE])) {
            return false;
        }

        return match ($filters[self::VIEW]) {
            'unused' => ! $this->usages->isUsed($id),
            'instances' => $this->usages->instances($id) !== [],
            'slugs' => (bool) $type->showSlugField,
            'statuses' => (bool) $type->showStatusField,
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

    /** @return array<string, mixed> */
    private function row(EntryType $type): array
    {
        $label = Html::encode($type->getUiLabel());
        $url = (string) $type->getCpEditUrl();
        $instanceCount = count($this->usages->instances((int) $type->id));

        // `data-cp-url` + `data-editable` are what Craft's table looks for to open a
        // slideout when the row is double-clicked; a click on the link still navigates.
        $name = Html::beginTag('div', ['class' => 'flex gap-1 items-center row-wrap']).
            app(ElementHtml::class)->chipHtml($type, [
                'labelHtml' => Html::a($label, $url, ['data-cp-url' => $url, 'data-editable' => true]),
            ]);

        if ($type->description) {
            $name .= Html::tag('craft-info-icon', Html::decodeDoubles(
                Markdown::parse(Html::encodeInvalidTags(Html::encode($type->description)), 'gfm-comment'),
            ));
        }

        // Worth flagging in every view, not just the instances one.
        if ($instanceCount > 0) {
            $name .= Html::tag('span', Html::encode(t('+ {count, number} {count, plural, =1{instance} other{instances}}', [
                'count' => $instanceCount,
            ], 'discontent')), ['class' => 'light']);
        }

        $name .= Html::endTag('div');

        $usedBy = $this->usages->usages((int) $type->id);

        return [
            'id' => $type->id,
            'name' => ['html' => $name],
            'handle' => ['html' => CopyAttribute::make()->value((string) $type->handle)->toHtml()],
            'usages' => $usedBy === []
                ? t('No usages', category: 'discontent')
                : ['html' => app(PreviewHtml::class)->componentPreviewHtml($usedBy, ['hyperlink' => true])],
            'slug' => $this->flag((bool) $type->showSlugField),
            'status' => $this->flag((bool) $type->showStatusField),
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

        $name = Html::tag('div',
            Icon::make()->name('arrow-turn-down-right')->toHtml().
            Html::a(Html::encode($instance['label']), $ownerUrl, ['data-cp-url' => $ownerUrl, 'data-editable' => true]),
            ['class' => 'flex gap-2 items-center', 'style' => 'padding-inline-start: 0.75rem'],
        );

        return [
            'id' => "$type->id-instance-$i",
            'name' => ['html' => $name],
            'handle' => ['html' => CopyAttribute::make()->value($instance['handle'])->toHtml()],
            'usages' => ['html' => app(PreviewHtml::class)->componentPreviewHtml([$owner], ['hyperlink' => true])],
            // Renaming doesn't touch these, so an instance shows what it inherits.
            'slug' => $this->flag((bool) $type->showSlugField),
            'status' => $this->flag((bool) $type->showStatusField),
            '_deletable' => false,
        ];
    }

    /** @return array{icon: string, label: string}|string */
    private function flag(bool $on): array|string
    {
        return $on ? ['icon' => 'check', 'label' => t('Yes', category: 'discontent')] : '—';
    }

    /** @return array<string, int|string|null> */
    private function pagination(int $total, int $page, int $perPage): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => $lastPage,
            'next_page_url' => null,
            'prev_page_url' => null,
            'from' => $total === 0 ? null : ($page - 1) * $perPage + 1,
            'to' => $total === 0 ? null : min($total, $page * $perPage),
        ];
    }
}
