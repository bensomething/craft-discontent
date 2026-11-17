<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Controllers;

use bensomething\discontent\Http\Controllers\Concerns\BuildsFilteredTables;
use bensomething\discontent\Plugin;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Cp\Components\CopyAttribute;
use CraftCms\Cms\Cp\Html\PreviewHtml;
use CraftCms\Cms\Edition;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Http\Requests\TableRequest;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Nodes\Table;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\Ui\UiResolver;
use CraftCms\Cms\Workflow\Models\Workflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

use function CraftCms\Cms\t;

/**
 * The Sections page: Craft's Settings → Sections list, plus the entry types each section
 * uses, the URI format its entries get, its workflow (on Pro, once there are any), a
 * section-type filter, URLs / versioning / workflow views, and search.
 *
 * There's no unused or instances view, as there is for fields and entry types: a
 * section is a top-level thing that nothing uses or renames.
 *
 * The filters rebuild the page through {@see renderUi()} (see {@see BuildsFilteredTables}).
 */
readonly class SectionsController
{
    use BuildsFilteredTables;

    private const string TYPE = 'type';

    private const string VIEW = 'view';

    private const array VIEWS = ['urls', 'no-urls', 'versioned', 'unversioned', 'workflow', 'no-workflow'];

    private bool $readOnly;

    public function __construct(GeneralConfig $generalConfig)
    {
        $this->readOnly = ! $generalConfig->allowAdminChanges;
    }

    /** The page, with any `?search=`, `?type=` and `?view=` applied, so a filtered link can be shared. */
    public function index(Request $request): CpScreenResponse
    {
        $values = $this->filterValues($request->query());

        return new CpScreenResponse()
            ->title(t('Sections', category: 'discontent'))
            ->addCrumb((string) Plugin::getInstance()->name, 'discontent')
            ->ui($this->ui($values), new UiContext(values: $values, refreshable: true), [
                'refreshUrl' => route('discontent.sections.render-ui'),
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
        $workflows = $this->workflows();

        $sections = $this->allSections()
            ->filter(fn (Section $section) => $this->matchesFilters($section, $filters, $workflows))
            ->filter(fn (Section $section) => $search === '' || $this->matchesSearch($section, $search, $workflows))
            ->sort(fn (Section $a, Section $b) => match ($request->orderBy()) {
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'type' => strcasecmp($this->typeLabel($a), $this->typeLabel($b)) ?: strcasecmp($a->getUiLabel(), $b->getUiLabel()),
                default => strcasecmp($a->getUiLabel(), $b->getUiLabel()),
            })
            ->values();

        if ($request->sortDir() === SORT_DESC) {
            $sections = $sections->reverse()->values();
        }

        [$paged, $pagination] = $this->paginate($sections, $request->page(), $request->limit());

        return new JsonResponse([
            'data' => Table::prepareRows(array_values($paged->map(fn (Section $section) => $this->row($section, $workflows))->all())),
            'pagination' => $pagination,
        ]);
    }

    /** @param array{search: string, type: string, view: string} $values */
    private function ui(array $values): Ui
    {
        $typeOptions = $this->typeOptions($values[self::TYPE]);
        $workflows = $this->workflows();

        $filters = [
            // "All" plus one type filters to everything, so only offer it with two or more.
            ...(count($typeOptions) > 2 ? $this->filterField(t('Section Type', category: 'discontent'), self::TYPE, $typeOptions) : []),
            ...$this->filterField(t('Filter', category: 'discontent'), self::VIEW, $this->viewOptions($values[self::VIEW], $workflows)),
        ];
        array_unshift($filters, $this->searchField(count($filters)));

        $table = Table::make($this->tableKey('sections', $values))
            ->columns([
                ['key' => 'name', 'label' => t('Name', category: 'discontent'), 'sortable' => true],
                ['key' => 'handle', 'label' => t('Handle', category: 'discontent'), 'sortable' => true],
                ['key' => 'type', 'label' => t('Type', category: 'discontent'), 'sortable' => true],
                ['key' => 'entryTypes', 'label' => t('Entry Types', category: 'discontent')],
                ['key' => 'urls', 'label' => t('URLs', category: 'discontent')],
                ['key' => 'versioning', 'label' => t('Versioning', category: 'discontent')],
                ...($workflows !== [] ? [['key' => 'workflow', 'label' => t('Workflow', category: 'discontent')]] : []),
            ])
            ->dataUrl(route('discontent.sections.data', array_filter($values)))
            // Craft's own column menu: each person shows, hides and reorders columns for
            // themselves, remembered across filter changes (the table's storage key is
            // its data URL's path, which filters don't change).
            ->toggleableColumns()
            ->emptyMessage(t('No sections match these filters.', category: 'discontent'))
            ->unless($this->readOnly, fn (Table $table) => $table
                ->createAction(t('New section', category: 'discontent'), route('craft.cp.settings.sections.create'))
                ->createActionInPageHeader()
                ->deletable());

        return Ui::make([...$filters, $table]);
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array{search: string, type: string, view: string}
     */
    private function filterValues(array $input): array
    {
        return [
            'search' => $this->searchValue($input),
            self::TYPE => $this->allowedValue($input, self::TYPE, array_column(SectionType::cases(), 'value')),
            self::VIEW => $this->allowedValue($input, self::VIEW, self::VIEWS),
        ];
    }

    /**
     * Workflow names by ID. Empty below Pro, where Craft doesn't offer workflows (a
     * section's leftover workflow does nothing there), and when none exist.
     *
     * @return array<int, string>
     */
    private function workflows(): array
    {
        if (! Edition::isAtLeast(Edition::Pro)) {
            return [];
        }

        /** @var array<int, string> */
        return Workflow::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * The section's workflow, by name: null when it has none, or none that counts here.
     *
     * @param  array<int, string>  $workflows  from {@see workflows()}
     */
    private function workflowName(Section $section, array $workflows): ?string
    {
        return $section->workflowId !== null ? ($workflows[$section->workflowId] ?? null) : null;
    }

    /** @return Collection<int, Section> */
    private function allSections(): Collection
    {
        return collect(Sections::getAllSections());
    }

    /**
     * Only the section types that exist here, plus whichever is selected, so a shared
     * link keeps its own filter. Plural, as Craft's entry sources say them.
     *
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(string $selected): array
    {
        $present = $this->allSections()->map(fn (Section $section) => $section->type?->value)->filter()->unique()->all();

        $labels = [
            SectionType::Single->value => t('Singles'),
            SectionType::Channel->value => t('Channels'),
            SectionType::Structure->value => t('Structures'),
        ];

        $options = [['value' => '', 'label' => t('All sections', category: 'discontent')]];

        foreach ($labels as $type => $label) {
            if (in_array($type, $present, true) || $selected === $type) {
                $options[] = ['value' => $type, 'label' => $label];
            }
        }

        return $options;
    }

    /**
     * A URLs pair, a Versioning pair and, when there are workflows, a Workflow pair (see
     * {@see viewPair()}), plus whichever is selected.
     *
     * @param  array<int, string>  $workflows
     * @return list<array{value: string, label: string, group?: string}>
     */
    private function viewOptions(string $selected, array $workflows): array
    {
        $sections = $this->allSections();
        $total = $sections->count();

        return [
            ['value' => '', 'label' => t('Show all', category: 'discontent')],
            ...$this->viewPair(
                t('URLs', category: 'discontent'),
                ['urls', t('Has URLs', category: 'discontent')],
                ['no-urls', t('No URLs', category: 'discontent')],
                $sections->filter(fn (Section $section) => $this->hasUrls($section))->count(),
                $total,
                $selected,
            ),
            ...$this->viewPair(
                t('Versioning', category: 'discontent'),
                ['versioned', t('Has versioning', category: 'discontent')],
                ['unversioned', t('No versioning', category: 'discontent')],
                $sections->filter(fn (Section $section) => $section->enableVersioning)->count(),
                $total,
                $selected,
            ),
            ...($workflows === [] && ! in_array($selected, ['workflow', 'no-workflow'], true) ? [] : $this->viewPair(
                t('Workflow', category: 'discontent'),
                ['workflow', t('Has workflow', category: 'discontent')],
                ['no-workflow', t('No workflow', category: 'discontent')],
                $sections->filter(fn (Section $section) => $this->workflowName($section, $workflows) !== null)->count(),
                $total,
                $selected,
            )),
        ];
    }

    /**
     * @param  array{search: string, type: string, view: string}  $filters
     * @param  array<int, string>  $workflows
     */
    private function matchesFilters(Section $section, array $filters, array $workflows): bool
    {
        if ($filters[self::TYPE] !== '' && $section->type?->value !== $filters[self::TYPE]) {
            return false;
        }

        return match ($filters[self::VIEW]) {
            'urls' => $this->hasUrls($section),
            'no-urls' => ! $this->hasUrls($section),
            'versioned' => $section->enableVersioning,
            'unversioned' => ! $section->enableVersioning,
            'workflow' => $this->workflowName($section, $workflows) !== null,
            'no-workflow' => $this->workflowName($section, $workflows) === null,
            default => true,
        };
    }

    /** @param  array<int, string>  $workflows */
    private function matchesSearch(Section $section, string $search, array $workflows): bool
    {
        // Entry types by the name they go by here, so a search finds the sections using one.
        $entryTypes = array_map(fn (EntryType $type) => $type->getUiLabel(), $section->getEntryTypes());

        foreach ([$section->getUiLabel(), (string) $section->handle, $this->typeLabel($section), $this->workflowName($section, $workflows) ?? '', ...$entryTypes] as $haystack) {
            if (str_contains(mb_strtolower($haystack), $search)) {
                return true;
            }
        }

        return false;
    }

    private function typeLabel(Section $section): string
    {
        return $section->type?->label() ?? '';
    }

    /**
     * Whether a section's entries are routed anywhere. URLs are per site, so routed in
     * at least one counts: that keeps "No URLs" meaning genuinely unrouted.
     */
    private function hasUrls(Section $section): bool
    {
        foreach ($section->getSiteSettings() as $siteSettings) {
            if ($siteSettings->hasUrls) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $workflows
     * @return array<string, mixed>
     */
    private function row(Section $section, array $workflows): array
    {
        $label = $section->getUiLabel();
        $url = route('craft.cp.settings.sections.edit', ['section' => $section->id]);

        return [
            'id' => $section->id,
            'name' => ['html' => $this->slideoutLink($label, $url, options: ['class' => 'font-bold'])],
            'handle' => ['html' => CopyAttribute::make()->value((string) $section->handle)->toHtml()],
            'type' => $this->typeLabel($section),
            // As the section names them: an entry type renamed here shows that name.
            'entryTypes' => ['html' => app(PreviewHtml::class)->componentPreviewHtml($section->getEntryTypes(), ['hyperlink' => true])],
            'urls' => $this->urlsCell($section),
            'versioning' => $this->flag($section->enableVersioning),
            'workflow' => ($workflow = $this->workflowName($section, $workflows)) !== null
                ? ['html' => Html::a(Html::encode($workflow), route('craft.cp.settings.workflows.edit', ['workflow' => $section->workflowId]))]
                : $this->faintDash(t('No workflow', category: 'discontent')),
            ...($this->readOnly ? [] : [
                '_deleteUrl' => route('craft.cp.settings.sections.destroy', ['section' => $section->id]),
                '_deleteConfirmMessage' => t('Are you sure you want to delete “{name}” and all its entries?', ['name' => $label], 'discontent'),
            ]),
        ];
    }

    /**
     * The URI format a section's entries get. Multi-site sections can have one per
     * site: the first is shown with a count of the others, rather than a wall of
     * near-identical URIs.
     *
     * @return array{html: string}|string
     */
    private function urlsCell(Section $section): array|string
    {
        $formats = [];

        foreach ($section->getSiteSettings() as $siteSettings) {
            if ($siteSettings->hasUrls && $siteSettings->uriFormat !== null) {
                $formats[$siteSettings->uriFormat] = true;
            }
        }

        if ($formats === []) {
            return t('No URLs', category: 'discontent');
        }

        $formats = array_keys($formats);

        // Long formats are cut off with an ellipsis, in full on hover, so one can't
        // stretch the table past the page.
        $html = Html::tag('code', Html::encode((string) $formats[0]), [
            'title' => (string) $formats[0],
            'style' => 'display: block; max-width: 20rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap',
        ]);

        if (count($formats) > 1) {
            $html .= Html::tag('span', Html::encode(t('+ {count, number} more', ['count' => count($formats) - 1], 'discontent')), ['class' => 'light']);
        }

        return ['html' => Html::tag('span', $html, ['class' => 'flex gap-2 items-center', 'style' => 'min-width: 0'])];
    }
}
