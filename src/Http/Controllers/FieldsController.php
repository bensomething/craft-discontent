<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Controllers;

use bensomething\discontent\Http\Controllers\Concerns\BuildsFilteredTables;
use bensomething\discontent\Plugin;
use bensomething\discontent\Support\FieldLayoutScan;
use bensomething\discontent\Support\LayoutOwner;
use CraftCms\Cms\Component\Contracts\Chippable;
use CraftCms\Cms\Component\Contracts\Iconic;
use CraftCms\Cms\Config\GeneralConfig;
use CraftCms\Cms\Cp\Components\CopyAttribute;
use CraftCms\Cms\Cp\Components\Icon;
use CraftCms\Cms\Cp\Enums\TableAppearance;
use CraftCms\Cms\Cp\Html\PreviewHtml;
use CraftCms\Cms\Cp\Icons;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Field\Enums\TranslationMethod;
use CraftCms\Cms\Field\MissingField;
use CraftCms\Cms\Http\Requests\TableRequest;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sites;
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
 * The Fields page: Craft's Settings → Fields list, plus a field-type filter (types in
 * use only), a view filter (unused, used under another name somewhere, used for search
 * keywords), and each field's instances.
 *
 * The filters rebuild the page through {@see renderUi()} (see {@see BuildsFilteredTables}).
 * Rows come from {@see data()}, which filters, searches, sorts and pages on the server,
 * so instance rows stay nested under their field.
 */
readonly class FieldsController
{
    use BuildsFilteredTables;

    private const string TYPE = 'type';

    private const string VIEW = 'view';

    private const array VIEWS = ['unused', 'instances', 'keywords', 'no-keywords', 'translatable', 'not-translatable'];

    private bool $readOnly;

    public function __construct(
        private FieldLayoutScan $scan,
        GeneralConfig $generalConfig,
    ) {
        $this->readOnly = ! $generalConfig->allowAdminChanges;
    }

    /** The page, with any `?search=`, `?type=` and `?view=` applied, so a filtered link can be shared. */
    public function index(Request $request): CpScreenResponse
    {
        $values = $this->filterValues($request->query());

        return new CpScreenResponse()
            ->title(t('Fields', category: 'discontent'))
            ->addCrumb((string) Plugin::getInstance()->name, 'discontent')
            ->ui($this->ui($values), new UiContext(values: $values, refreshable: true), [
                'refreshUrl' => route('discontent.fields.render-ui'),
                // Full width, like an element index: these tables have more columns than
                // Craft's settings width fits.
                'contentMaxWidth' => false,
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

        $fields = $this->allFields()
            ->filter(fn (FieldInterface $field) => $this->matchesFilters($field, $filters))
            ->filter(fn (FieldInterface $field) => $search === '' || $this->matchesSearch($field, $search))
            ->sort(fn (FieldInterface $a, FieldInterface $b) => match ($request->orderBy()) {
                'handle' => strcasecmp((string) $a->handle, (string) $b->handle),
                'type' => strcasecmp($this->typeLabel($a), $this->typeLabel($b)) ?: strcasecmp((string) $a->name, (string) $b->name),
                default => strcasecmp((string) $a->name, (string) $b->name) ?: strcasecmp((string) $a->handle, (string) $b->handle),
            })
            ->values();

        if ($request->sortDir() === SORT_DESC) {
            $fields = $fields->reverse()->values();
        }

        [$paged, $pagination] = $this->paginate($fields, $request->page(), $request->limit());

        $rows = [];

        foreach ($paged as $field) {
            $rows[] = $this->row($field);

            // Nested under their field in the instances view, so paging and sorting stay
            // at the field level and the nesting rides along.
            if ($filters[self::VIEW] === 'instances') {
                foreach ($this->scan->instances((string) $field->uid) as $i => $instance) {
                    $rows[] = $this->instanceRow($field, $instance, $i);
                }
            }
        }

        return new JsonResponse([
            'data' => Table::prepareRows($rows),
            'pagination' => $pagination,
        ]);
    }

    /** @param array{search: string, type: string, view: string} $values */
    private function ui(array $values): Ui
    {
        $typeOptions = $this->typeOptions();

        $filters = [
            // "All" plus one type filters to everything, so only offer it with two or more.
            ...(count($typeOptions) > 2 ? $this->filterField(t('Field Type', category: 'discontent'), self::TYPE, $typeOptions) : []),
            ...$this->filterField(t('Filter', category: 'discontent'), self::VIEW, $this->viewOptions($values[self::VIEW])),
        ];
        array_unshift($filters, $this->searchField(count($filters)));

        $table = Table::make($this->tableKey('fields', $values))
            ->appearance(TableAppearance::Flush)
            ->columns([
                ['key' => 'name', 'label' => t('Name', category: 'discontent'), 'sortable' => true],
                ['key' => 'handle', 'label' => t('Handle', category: 'discontent'), 'sortable' => true],
                ['key' => 'type', 'label' => t('Type', category: 'discontent'), 'sortable' => true],
                ['key' => 'usages', 'label' => t('Used by', category: 'discontent')],
                ['key' => 'keywords', 'label' => t('Used for keywords', category: 'discontent')],
                ...(Sites::isMultiSite() ? [['key' => 'translation', 'label' => t('Translation Method', category: 'discontent')]] : []),
            ])
            ->dataUrl(route('discontent.fields.data', array_filter($values)))
            // Craft's own column menu: each person shows, hides and reorders columns for
            // themselves, remembered across filter changes (the table's storage key is
            // its data URL's path, which filters don't change).
            ->toggleableColumns()
            ->emptyMessage(t('No fields match these filters.', category: 'discontent'))
            ->unless($this->readOnly, fn (Table $table) => $table
                ->createAction(t('New field', category: 'discontent'), route('craft.cp.settings.fields.create'))
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
            self::TYPE => $this->allowedValue($input, self::TYPE, array_values($this->allFields()->map(fn (FieldInterface $field) => $field::class)->unique()->all())),
            self::VIEW => $this->allowedValue($input, self::VIEW, self::VIEWS),
        ];
    }

    /** @return Collection<int, FieldInterface> */
    private function allFields(): Collection
    {
        return Fields::getAllFields();
    }

    /**
     * The field types in use, by name. A shared link's type is always among them: one
     * not in use is dropped by {@see filterValues()}.
     *
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        $types = $this->allFields()
            ->mapWithKeys(fn (FieldInterface $field) => [$field::class => $this->typeLabel($field)])
            ->sort(fn (string $a, string $b) => strcasecmp($a, $b));

        return [
            ['value' => '', 'label' => t('All field types', category: 'discontent')],
            ...$types->map(fn (string $label, string $class) => ['value' => $class, 'label' => $label])->values()->all(),
        ];
    }

    /**
     * Unused and Has instances where they have something to show, then the Keywords pair
     * and, on multi-site installs, the Translation pair (see {@see viewPair()}), plus
     * whichever is selected.
     *
     * @return list<array{value: string, label: string, group?: string}>
     */
    private function viewOptions(string $selected): array
    {
        $fields = $this->allFields();

        $options = [['value' => '', 'label' => t('Show all', category: 'discontent')]];

        if ($selected === 'unused' || $fields->contains(fn (FieldInterface $field) => $this->scan->usageCount($field) === 0)) {
            $options[] = ['value' => 'unused', 'label' => t('Unused', category: 'discontent')];
        }

        if ($selected === 'instances' || $this->scan->hasInstances()) {
            $options[] = ['value' => 'instances', 'label' => t('Has instances', category: 'discontent')];
        }

        return [...$options, ...$this->viewPair(
            t('Keywords', category: 'discontent'),
            ['keywords', t('Used for keywords', category: 'discontent')],
            ['no-keywords', t('Not used for keywords', category: 'discontent')],
            $fields->filter(fn (FieldInterface $field) => (bool) $field->searchable)->count(),
            $fields->count(),
            $selected,
        ), ...(! Sites::isMultiSite() && ! in_array($selected, ['translatable', 'not-translatable'], true) ? [] : $this->viewPair(
            t('Translation', category: 'discontent'),
            ['translatable', t('Translatable', category: 'discontent')],
            ['not-translatable', t('Not translatable', category: 'discontent')],
            $fields->filter(fn (FieldInterface $field) => ! in_array($this->translationMethod($field), [null, TranslationMethod::None], true))->count(),
            $fields->filter(fn (FieldInterface $field) => $this->translationMethod($field) !== null)->count(),
            $selected,
        ))];
    }

    /** @param array{search: string, type: string, view: string} $filters */
    private function matchesFilters(FieldInterface $field, array $filters): bool
    {
        if ($filters[self::TYPE] !== '' && $field::class !== $filters[self::TYPE]) {
            return false;
        }

        return match ($filters[self::VIEW]) {
            'unused' => $this->scan->usageCount($field) === 0,
            'instances' => $this->scan->instances((string) $field->uid) !== [],
            'keywords' => (bool) $field->searchable,
            'no-keywords' => ! $field->searchable,
            'translatable' => ! in_array($this->translationMethod($field), [null, TranslationMethod::None], true),
            'not-translatable' => $this->translationMethod($field) === TranslationMethod::None,
            default => true,
        };
    }

    /**
     * Name, handle, type and instructions, or any instance's label, handle or layout, so
     * a field can be found by the name it goes by somewhere else.
     */
    private function matchesSearch(FieldInterface $field, string $search): bool
    {
        $haystacks = [(string) $field->name, (string) $field->handle, $this->typeLabel($field), (string) $field->instructions];

        foreach ($this->scan->instances((string) $field->uid) as $instance) {
            $haystacks[] = (string) $instance['label'];
            $haystacks[] = $instance['handle'];
            $haystacks[] = $instance['owner']->label;
        }

        foreach ($haystacks as $haystack) {
            if (str_contains(mb_strtolower($haystack), $search)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The field's chosen translation method, or null when its type fixes it (Matrix,
     * Addresses and Content Block are always per site; their propagation method is what
     * varies). Showing a fixed method as though it were a setting would mislead.
     */
    private function translationMethod(FieldInterface $field): ?TranslationMethod
    {
        if (count($field::supportedTranslationMethods()) < 2) {
            return null;
        }

        return TranslationMethod::tryFrom($field->translationMethod) ?? TranslationMethod::None;
    }

    /**
     * How a field's values are translated, said shortly (the column names the rest), or a
     * faint dash when they aren't or its type gives no choice. A custom method shows its
     * translation key format on hover.
     *
     * @return array{html: string}|string
     */
    private function translationCell(FieldInterface $field): array|string
    {
        $method = $this->translationMethod($field);

        return match ($method) {
            null => $this->faintDash(t('Set by the field type', category: 'discontent')),
            TranslationMethod::None => $this->faintDash(t('Not translatable', category: 'discontent')),
            TranslationMethod::Site => t('Each site', category: 'discontent'),
            TranslationMethod::SiteGroup => t('Each site group', category: 'discontent'),
            TranslationMethod::Language => t('Each language', category: 'discontent'),
            TranslationMethod::Custom => ['html' => Html::tag('span', Html::encode(t('Custom', category: 'discontent')), [
                'title' => (string) $field->translationKeyFormat,
            ])],
        };
    }

    private function typeLabel(FieldInterface $field): string
    {
        return $field instanceof MissingField ? (string) $field->expectedType : $field::displayName();
    }

    /** @return array<string, mixed> */
    private function row(FieldInterface $field): array
    {
        $name = t((string) $field->name, category: 'site');
        $url = $field->getCpEditUrl();

        $nameHtml = Html::tag('div',
            $this->slideoutLink($name, $url, options: ['class' => 'font-bold']).
            $this->instanceNote(
                count($this->scan->instances((string) $field->uid)),
                route('discontent.fields', ['search' => (string) $field->handle, self::VIEW => 'instances']),
            ),
            ['class' => 'flex gap-2 items-center row-wrap'],
        );

        return [
            'id' => $field->id,
            'name' => ['html' => $nameHtml],
            'handle' => ['html' => CopyAttribute::make()->value((string) $field->handle)->toHtml()],
            'type' => $this->typeCell($field),
            'usages' => $this->usagesCell($this->scan->usageCount($field), $this->scan->owners((string) $field->uid)),
            'keywords' => $this->flag((bool) $field->searchable),
            'translation' => $this->translationCell($field),
            ...($this->readOnly ? [] : [
                '_deleteUrl' => route('craft.cp.settings.fields.destroy', ['fieldId' => $field->id]),
                '_deleteConfirmMessage' => t('Are you sure you want to delete “{name}”?', ['name' => $name], 'discontent'),
            ]),
        ];
    }

    /**
     * A field's appearance in a layout under its own label or handle, linked to the
     * layout's owner, where Craft edits it: a double-click opens that in a slideout.
     *
     * @param  array{label: ?string, handle: string, owner: LayoutOwner}  $instance
     * @return array<string, mixed>
     */
    private function instanceRow(FieldInterface $field, array $instance, int $i): array
    {
        $owner = $instance['owner'];
        $label = $instance['label'] ?? t((string) $field->name, category: 'site');

        return [
            'id' => "$field->id-instance-$i",
            'name' => ['html' => $this->instanceName($this->slideoutLink($label, $owner->url, $owner->slideoutUrl))],
            'handle' => ['html' => CopyAttribute::make()->value($instance['handle'])->toHtml()],
            'type' => t('Instance', category: 'discontent'),
            'usages' => $this->ownerCell($owner),
            // The same field, so it feeds search the same way.
            'keywords' => $this->flag((bool) $field->searchable),
            'translation' => $this->translationCell($field),
            '_deletable' => false,
        ];
    }

    /** @return array{html: string}|string */
    private function typeCell(FieldInterface $field): array|string
    {
        if ($field instanceof MissingField) {
            return t('Missing: {type}', ['type' => $this->typeLabel($field)], 'discontent');
        }

        $iconName = $field instanceof Iconic ? $field->getIcon() : null;
        $icon = $iconName !== null ? Icons::resolveIconData($iconName) : null;

        return ['html' => Html::tag('div',
            ($icon ? Icon::make()->configure($icon)->toHtml() : '').Html::tag('span', Html::encode($this->typeLabel($field))),
            ['class' => 'flex items-center gap-2'],
        )];
    }

    /**
     * The field's layouts as chips: the first, then a "+N" that expands to the rest, like
     * the other chip columns. Chips are only shown when they tell the whole story: the
     * scan has to have seen every layout Craft counts, and each needs an owner to chip.
     * Otherwise a list would quietly understate where the field is used, so it falls
     * back to Craft's count, except for a lone provider-less layout, which is named.
     *
     * @param  list<LayoutOwner>  $owners
     * @return array{html: string}|string
     */
    private function usagesCell(int $count, array $owners): array|string
    {
        if ($count === 0) {
            return $this->quiet(t('No usages', category: 'discontent'));
        }

        if ($count === count($owners)) {
            $providers = array_values(array_filter(array_map(fn (LayoutOwner $owner) => $owner->provider, $owners)));

            if (count($providers) === $count) {
                usort($providers, fn (Chippable $a, Chippable $b) => strcasecmp($a->getUiLabel(), $b->getUiLabel()));

                return ['html' => app(PreviewHtml::class)->componentPreviewHtml($providers, ['hyperlink' => true])];
            }

            if ($count === 1) {
                return $owners[0]->label;
            }
        }

        return t('{count, number} {count, plural, =1{layout} other{layouts}}', ['count' => $count], 'discontent');
    }

    /** @return array{html: string}|string */
    private function ownerCell(LayoutOwner $owner): array|string
    {
        return $owner->provider !== null
            ? ['html' => app(PreviewHtml::class)->componentPreviewHtml([$owner->provider], ['hyperlink' => true])]
            : $owner->label;
    }
}
