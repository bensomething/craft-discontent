<?php

namespace bensomething\discontent\controllers;

use bensomething\discontent\helpers\ViewOptions;
use Craft;
use craft\base\Chippable;
use craft\base\Colorable;
use craft\base\CpEditable;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\base\Iconic;
use craft\elements\GlobalSet;
use craft\fields\MissingField;
use craft\helpers\AdminTable;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Volume;
use craft\web\Controller;
use yii\web\Response;

/**
 * The Fields tool: the same list Craft shows at Settings → Fields, plus a
 * field-type dropdown and a view dropdown — all fields, just the unused ones,
 * or just the instanced ones with their instances nested underneath. All
 * filtering happens server-side in {@see actionTableData()}.
 */
class FieldsController extends Controller
{
    /**
     * @var array{owners: array<string, array<string, array>>, instances: array<string, list<array>>}
     * @see layoutScan()
     */
    private array $layoutScan;

    public function beforeAction($action): bool
    {
        // Admin-only, but usable even when admin changes are disabled — the
        // tool is read-only (deletes go through Craft’s own admin-gated action).
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    /**
     * Redirect target for the hijacked native Settings → Fields URL, so the
     * canonical /discontent/fields URL is what the user lands on.
     */
    public function actionRedirect(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('discontent/fields'));
    }

    public function actionIndex(): Response
    {
        $fieldsService = Craft::$app->getFields();

        // Only list field types that are actually in use, and count the unused
        // and the searchable — all in a single pass over the fields.
        $usedTypes = [];
        $total = 0;
        $unusedCount = 0;
        $searchableCount = 0;
        $translatableCount = 0;
        $translationChoiceCount = 0;
        foreach ($fieldsService->getAllFields() as $field) {
            $total++;
            $usedTypes[$field::class] ??= $field->displayName();
            if (empty($fieldsService->findFieldUsages($field))) {
                $unusedCount++;
            }
            if ($field->searchable) {
                $searchableCount++;
            }
            $method = $this->translationMethod($field);
            if ($method !== null) {
                $translationChoiceCount++;
                if ($method !== Field::TRANSLATION_METHOD_NONE) {
                    $translatableCount++;
                }
            }
        }

        // Translation only means anything with more than one site.
        $isMultiSite = Craft::$app->getIsMultiSite();

        $types = [];
        foreach ($usedTypes as $value => $label) {
            $types[] = ['value' => $value, 'label' => $label];
        }
        usort($types, fn($a, $b) => strcasecmp($a['label'], $b['label']));

        // Reflect any shared-link filters back into the toolbar controls.
        $request = Craft::$app->getRequest();
        $initialView = (string)$request->getParam('view', '');

        // Each view is offered only when it has something to show: an unused
        // field, a field used under a different name or handle somewhere, or a
        // field feeding search keywords (and not all of them doing so, which
        // would make the view a no-op). A shared link keeps its own view
        // available regardless.
        $views = [['value' => '', 'label' => Craft::t('discontent', 'Show all')]];
        if ($unusedCount > 0 || $initialView === 'unused') {
            $views[] = ['value' => 'unused', 'label' => Craft::t('discontent', 'Unused')];
        }
        if ($this->layoutScan()['instances'] !== [] || $initialView === 'instances') {
            $views[] = ['value' => 'instances', 'label' => Craft::t('discontent', 'Has instances')];
        }
        array_push($views, ...ViewOptions::pair(
            Craft::t('discontent', 'Keywords'),
            ['keywords', Craft::t('discontent', 'Used for keywords')],
            ['no-keywords', Craft::t('discontent', 'Not used for keywords')],
            $searchableCount,
            $total,
            $initialView,
        ));
        if ($isMultiSite || in_array($initialView, ['translatable', 'not-translatable'], true)) {
            // Out of the fields whose type gives a choice (see translationMethod()).
            array_push($views, ...ViewOptions::pair(
                Craft::t('discontent', 'Translation'),
                ['translatable', Craft::t('discontent', 'Translatable')],
                ['not-translatable', Craft::t('discontent', 'Not translatable')],
                $translatableCount,
                $translationChoiceCount,
                $initialView,
            ));
        }

        return $this->renderTemplate('discontent/fields', [
            'fieldTypes' => $types,
            // Nothing to choose between if "Show all" is the only view.
            'views' => count($views) > 1 ? $views : [],
            // Rendered once for the keywords column rather than per row
            // (there’s no iconSvg() in Twig).
            'checkIcon' => Cp::iconSvg('check'),
            'showTranslation' => $isMultiSite,
            'initialSearch' => (string)$request->getParam('search', ''),
            'initialFilter' => (string)$request->getParam('type', ''),
            'initialView' => $initialView,
        ]);
    }

    public function actionTableData(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $page = max(1, (int)$request->getParam('page', 1));
        $limit = max(1, (int)$request->getParam('per_page', 100));
        $search = trim((string)$request->getParam('search', ''));
        $type = $request->getParam('type');
        // The views are mutually exclusive: unused fields have no instances.
        $view = (string)$request->getParam('view', '');
        $unused = $view === 'unused';
        $showInstances = $view === 'instances';
        $orderBy = match ($request->getParam('sort.0.field')) {
            '__slot:handle' => 'handle',
            'type' => 'type',
            default => 'name',
        };
        $descending = $request->getParam('sort.0.direction') === 'desc';

        $fieldsService = Craft::$app->getFields();

        /** @var FieldInterface[] $fields */
        $fields = $fieldsService->getAllFields();

        // Type filter
        if ($type) {
            $fields = array_filter($fields, fn(FieldInterface $f) => $f::class === $type);
        }

        // Resolved up front so the search can match instances too.
        $scan = $this->layoutScan();
        $instances = $showInstances ? $scan['instances'] : [];

        // Search filter — name, handle, type label, instructions, and (when
        // instances are shown) any instance’s label, handle or layout. A field
        // matched by one of its instances keeps all of them, so the nesting
        // still reads as “this field, everywhere it’s used”.
        if ($search !== '') {
            $term = mb_strtolower($search);
            $fields = array_filter($fields, function(FieldInterface $f) use ($term, $instances) {
                return str_contains(mb_strtolower((string)$f->name), $term)
                    || str_contains(mb_strtolower((string)$f->handle), $term)
                    || str_contains(mb_strtolower((string)$f->displayName()), $term)
                    || str_contains(mb_strtolower((string)$f->instructions), $term)
                    || $this->instancesMatch($instances[$f->uid] ?? [], $term);
            });
        }

        // Resolve usages once per field, then apply the "unused" filter.
        $usageCounts = [];
        foreach ($fields as $field) {
            $usageCounts[$field->id] = count($fieldsService->findFieldUsages($field));
        }
        if ($unused) {
            $fields = array_filter($fields, fn(FieldInterface $f) => ($usageCounts[$f->id] ?? 0) === 0);
        }

        // The instances view is about the instances, so drop the fields that
        // don’t have any — otherwise the nested rows are lost in the list.
        if ($showInstances) {
            $fields = array_filter($fields, fn(FieldInterface $f) => isset($instances[$f->uid]));
        }

        if ($view === 'keywords') {
            $fields = array_filter($fields, fn(FieldInterface $f) => $f->searchable);
        } elseif ($view === 'no-keywords') {
            $fields = array_filter($fields, fn(FieldInterface $f) => !$f->searchable);
        } elseif ($view === 'translatable') {
            $fields = array_filter($fields, fn(FieldInterface $f) => !in_array($this->translationMethod($f), [null, Field::TRANSLATION_METHOD_NONE], true));
        } elseif ($view === 'not-translatable') {
            $fields = array_filter($fields, fn(FieldInterface $f) => $this->translationMethod($f) === Field::TRANSLATION_METHOD_NONE);
        }

        // Sort
        $fields = array_values($fields);
        usort($fields, function(FieldInterface $a, FieldInterface $b) use ($orderBy) {
            return match ($orderBy) {
                'handle' => strcasecmp((string)$a->handle, (string)$b->handle),
                'type' => strcasecmp($a->displayName(), $b->displayName()) ?: strcasecmp((string)$a->name, (string)$b->name),
                default => strcasecmp((string)$a->name, (string)$b->name) ?: strcasecmp((string)$a->handle, (string)$b->handle),
            };
        });
        if ($descending) {
            $fields = array_reverse($fields);
        }

        $total = count($fields);
        $paged = array_slice($fields, ($page - 1) * $limit, $limit);

        $tableData = [];
        $arrowIcon = Cp::iconSvg('arrow-turn-down-right');

        foreach ($paged as $field) {
            $count = $usageCounts[$field->id] ?? 0;
            $fieldName = Craft::t('site', $field->name);
            // Instances are worth flagging in every view, not just theirs.
            $instanceCount = count($scan['instances'][$field->uid] ?? []);

            $tableData[] = [
                'id' => $field->id,
                // `title` is what the delete confirmation names; the Name cell
                // itself is rendered by us (see nameHtml()).
                'title' => $fieldName,
                'name' => $this->nameHtml($fieldName, $field->getCpEditUrl(), $instanceCount),
                'handle' => $field->handle,
                'type' => [
                    'isMissing' => $field instanceof MissingField,
                    'label' => $field instanceof MissingField ? $field->expectedType : $field->displayName(),
                    'icon' => Cp::iconSvg($field instanceof Iconic ? $field->getIcon() : $field::icon()),
                ],
                // A field used in one place is better named than counted, so
                // show that layout the way its instances show theirs. Anything
                // more stays a count — Craft’s own wording.
                'usages' => $this->usagesHtml($count, $scan['owners'][$field->uid] ?? []),
                // Whether this field’s values become search keywords.
                'keywords' => $field->searchable,
                'translation' => $this->translationHtml($field),
            ];

            // Instance rows, nested directly under the field they belong to.
            // They’re ordinary table rows the admin table knows nothing about,
            // so pagination and sorting stay field-level and the nesting rides
            // along. `_showDelete` keeps the delete button off them — they
            // aren’t deletable things.
            foreach ($instances[$field->uid] ?? [] as $i => $instance) {
                $instanceName = $instance['label'] ?? $fieldName;
                $tableData[] = [
                    'id' => "$field->id-instance-$i",
                    'title' => $instanceName,
                    'name' => $this->instanceNameHtml($instanceName, $instance['owner']['url'], $arrowIcon),
                    'handle' => $instance['handle'],
                    'type' => ['isInstance' => true, 'icon' => $arrowIcon],
                    'usages' => $this->ownerHtml($instance['owner']),
                    // An instance is the same field, so it feeds search the
                    // same way.
                    'keywords' => $field->searchable,
                    'translation' => $this->translationHtml($field),
                    // Where a double-click takes this row: the layout’s owner.
                    'instance' => [
                        'slideout' => $instance['owner']['slideout'],
                    ],
                    '_showDelete' => false,
                ];
            }
        }

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $tableData,
        ]);
    }

    /**
     * One pass over every field layout in the install, giving two things per
     * field UID: the layouts it’s used in (`owners`, keyed by layout) and its
     * instances (`instances`).
     *
     * An “instance” is a field’s appearance in a field layout: Craft 5 lets
     * each one carry its own label and handle, so the same field can surface as
     * “Intro” in one entry type and “Body” in another. Only instances that
     * differ from the field are collected — one that uses the field’s own name
     * and handle is the field as it comes, and repeating it for every layout is
     * just noise.
     *
     * Layouts are collected from the element types rather than
     * {@see \craft\services\Fields::getAllLayouts()} because that’s what gets
     * them with their providers attached — the provider is the entry type,
     * volume or global set the layout belongs to, and the only thing that can
     * name it or give it somewhere to link. (Craft does the same when it labels
     * a field’s usages on the field edit screen.) Element types that don’t
     * report their layouts this way are simply absent, so callers fall back to
     * a plain count.
     *
     * @return array{
     *     owners: array<string, array<string, array>>,
     *     instances: array<string, list<array{label: string|null, handle: string, owner: array}>>,
     * }
     */
    private function layoutScan(): array
    {
        if (isset($this->layoutScan)) {
            return $this->layoutScan;
        }

        $owners = [];
        $instances = [];
        $seen = [];

        $fieldsByUid = [];
        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            $fieldsByUid[$field->uid] = $field;
        }

        /** @var class-string<ElementInterface> $elementType */
        foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
            foreach ($elementType::fieldLayouts(null) as $layout) {
                // The same layout can be reachable from more than one source.
                $layoutKey = $layout->uid ?? spl_object_id($layout);
                if (isset($seen[$layoutKey])) {
                    continue;
                }
                $seen[$layoutKey] = true;

                $owner = $this->layoutOwner($layout, $elementType);

                foreach ($layout->getCustomFieldElements() as $element) {
                    $handle = $element->attribute();
                    if ($handle === '') {
                        continue;
                    }

                    $fieldUid = $element->getFieldUid();
                    $field = $fieldsByUid[$fieldUid] ?? null;
                    if ($field === null) {
                        continue;
                    }

                    // Keyed by layout: a field used twice in one layout is
                    // still only used by one layout.
                    $owners[$fieldUid][$layoutKey] = $owner;

                    // Skip the field as-is: same handle, no relabelling. (An
                    // instance keeping the handle but taking a new label is
                    // still worth showing.)
                    $label = $element->label();
                    if (
                        $handle === $field->handle &&
                        ($label === null || $label === Craft::t('site', $field->name))
                    ) {
                        continue;
                    }

                    $instances[$fieldUid][] = [
                        'label' => $label,
                        'handle' => $handle,
                        'owner' => $owner,
                    ];
                }
            }
        }

        foreach ($instances as &$fieldInstances) {
            usort($fieldInstances, fn(array $a, array $b) => strcasecmp($a['owner']['label'], $b['owner']['label'])
                ?: strcasecmp((string)$a['label'], (string)$b['label']));
        }

        return $this->layoutScan = [
            'owners' => $owners,
            'instances' => $instances,
        ];
    }

    /**
     * The thing a field layout belongs to, as label / link / icon.
     *
     * @param class-string<ElementInterface> $elementType
     * @return array{label: string, url: string|null, icon: string|null, color: string|null, slideout: array|null, provider: Chippable|null}
     */
    private function layoutOwner(FieldLayout $layout, string $elementType): array
    {
        $provider = $layout->provider;

        if ($provider instanceof Chippable) {
            // Global sets are edited from their settings, not their element
            // edit page — the same exception Craft makes.
            if ($provider instanceof GlobalSet) {
                $url = UrlHelper::cpUrl("settings/globals/$provider->id");
            } else {
                $url = $provider instanceof CpEditable ? $provider->getCpEditUrl() : null;
            }

            $icon = $provider instanceof Iconic ? $provider->getIcon() : null;

            return [
                'label' => $provider->getUiLabel(),
                'url' => $url,
                'icon' => $icon !== null ? Cp::iconSvg($icon) : null,
                'color' => $provider instanceof Colorable ? $provider->getColor()?->value : null,
                'slideout' => $this->ownerSlideout($provider),
                'provider' => $provider,
            ];
        }

        // Some layouts have no provider (the users layout, say). They still
        // have a home worth naming, just nothing to link to.
        return [
            'label' => $elementType::displayName(),
            'url' => null,
            'icon' => null,
            'color' => null,
            'slideout' => null,
            'provider' => null,
        ];
    }

    /**
     * The CP screen that owns a layout, if it’s one we can open in a slideout.
     *
     * An instance’s settings (label, handle, instructions, conditions…) live in
     * its layout, and Craft only edits them through that layout’s field layout
     * designer — so double-clicking an instance opens the layout’s owner, where
     * the designer is.
     *
     * Only the providers Craft exposes as CP screens can be opened this way;
     * the rest fall back to their plain edit link.
     *
     * @return array{action: string, param: string, id: int}|null
     */
    private function ownerSlideout(Chippable $provider): ?array
    {
        return match (true) {
            $provider instanceof EntryType => [
                'action' => 'entry-types/edit',
                'param' => 'entryTypeId',
                'id' => $provider->id,
            ],
            $provider instanceof Volume => [
                'action' => 'volumes/edit-volume',
                'param' => 'volumeId',
                'id' => $provider->id,
            ],
            default => null,
        };
    }

    /**
     * The Name cell for a field: its link, plus a note of how many instances it
     * has. The count shows in every view — it’s a standing property of the
     * field, and it’s what tells you the instances view has anything for it.
     *
     * Rendered here rather than left to the admin table’s own title slot, which
     * has no room for anything beside the link.
     */
    private function nameHtml(string $name, ?string $url, int $instanceCount): string
    {
        $html = $url !== null
            ? Html::a(Html::encode($name), $url, ['class' => 'cell-bold'])
            : Html::tag('span', Html::encode($name), ['class' => 'cell-bold']);

        if ($instanceCount > 0) {
            $html .= Html::tag('span', Craft::t('discontent', '+ {count, number} {count, plural, =1{instance} other{instances}}', [
                'count' => $instanceCount,
            ]), ['class' => 'ct-note']);
        }

        return Html::tag('div', $html, ['class' => ['ct-name', 'flex', 'flex-nowrap', 'gap-s']]);
    }

    /**
     * The Name cell for an instance row: the ↳ arrow, then the instance’s own
     * label linking to the layout it lives in.
     */
    private function instanceNameHtml(string $name, ?string $url, string $arrowIcon): string
    {
        $html = Html::tag('div', $arrowIcon, ['class' => ['cp-icon', 'small']]);

        $html .= $url !== null
            ? Html::a(Html::encode($name), $url)
            : Html::tag('span', Html::encode($name));

        return Html::tag('div', $html, ['class' => ['flex', 'flex-nowrap', 'gap-s']]);
    }

    /**
     * The field’s chosen translation method, or null when its type fixes it
     * (Matrix and Addresses are always per site; their propagation method is
     * what varies). Showing a fixed method as though it were a setting would
     * mislead.
     */
    private function translationMethod(FieldInterface $field): ?string
    {
        if (count($field::supportedTranslationMethods()) < 2) {
            return null;
        }

        return $field->translationMethod;
    }

    /**
     * How a field’s values are translated, said shortly (the column names the
     * rest), or a faint dash when they aren’t or its type gives no choice. A
     * custom method shows its translation key format on hover.
     */
    private function translationHtml(FieldInterface $field): string
    {
        $method = $this->translationMethod($field);

        $label = match ($method) {
            Field::TRANSLATION_METHOD_SITE => Craft::t('discontent', 'Each site'),
            Field::TRANSLATION_METHOD_SITE_GROUP => Craft::t('discontent', 'Each site group'),
            Field::TRANSLATION_METHOD_LANGUAGE => Craft::t('discontent', 'Each language'),
            Field::TRANSLATION_METHOD_CUSTOM => Craft::t('discontent', 'Custom'),
            default => null,
        };

        if ($label === null) {
            return Html::tag('span', '&mdash;', ['class' => 'ct-faint', 'aria-hidden' => 'true']) .
                Html::tag('span', Html::encode($method === null
                    ? Craft::t('discontent', 'Set by the field type')
                    : Craft::t('discontent', 'Not translatable')), ['class' => 'visually-hidden']);
        }

        return Html::tag('span', Html::encode($label), $method === Field::TRANSLATION_METHOD_CUSTOM
            ? ['title' => (string)$field->translationKeyFormat]
            : []);
    }

    /**
     * The “Used by” cell for a field: its layouts as chips — the first, then a
     * “+N” that expands to the rest, like the other chip columns. Null when
     * unused — the table renders its own “No usages”.
     *
     * Chips are only shown when they tell the whole story: the scan has to
     * have seen every layout Craft counts (see {@see layoutScan()}), and each
     * has to have an owner to chip. Otherwise a list would quietly understate
     * where the field is used, so it falls back to Craft’s count — except a
     * lone provider-less layout (the users layout, say), which is named.
     *
     * @param array<string, array> $owners
     */
    private function usagesHtml(int $count, array $owners): ?string
    {
        if ($count === 0) {
            return null;
        }

        if ($count === count($owners)) {
            $providers = array_filter(array_column($owners, 'provider'));

            if (count($providers) === $count) {
                usort($providers, fn(Chippable $a, Chippable $b) => strcasecmp($a->getUiLabel(), $b->getUiLabel()));

                return Cp::componentPreviewHtml($providers, ['hyperlink' => true]);
            }

            if ($count === 1) {
                return $this->ownerHtml(reset($owners));
            }
        }

        return Craft::t('app', '{count, number} {count, plural, =1{layout} other{layouts}}', ['count' => $count]);
    }

    /**
     * A layout as a chip: icon + name, unlinked (rows that show one already
     * link to it).
     *
     * @param array{label: string, icon: string|null, color: string|null} $owner
     */
    private function ownerHtml(array $owner): string
    {
        $html = Html::tag('div', $owner['icon'] ?? Cp::iconSvg('files'), [
            'class' => array_filter(['cp-icon', 'small', $owner['color']]),
        ]);

        $html .= Html::tag('span', Html::encode($owner['label']));

        return Html::tag('span', $html, ['class' => ['flex', 'flex-nowrap', 'gap-s']]);
    }

    /**
     * @param list<array{label: string|null, handle: string, owner: array}> $instances
     */
    private function instancesMatch(array $instances, string $term): bool
    {
        foreach ($instances as $instance) {
            if (
                str_contains(mb_strtolower((string)$instance['label']), $term) ||
                str_contains(mb_strtolower($instance['handle']), $term) ||
                str_contains(mb_strtolower($instance['owner']['label']), $term)
            ) {
                return true;
            }
        }

        return false;
    }
}
