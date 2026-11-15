<?php

namespace bensomething\discontent\controllers;

use bensomething\discontent\helpers\ViewOptions;
use Craft;
use craft\base\CpEditable;
use craft\base\ElementContainerFieldInterface;
use craft\base\FieldInterface;
use craft\helpers\AdminTable;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Markdown;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\web\Controller;
use yii\web\Response;

/**
 * The Entry Types tool: Craft’s Settings → Entry Types list plus a usage filter
 * (used by a Section, a Matrix field, or a nested CKEditor field) and a view
 * dropdown — all entry types, the unused ones, or the instanced ones with their
 * instances nested underneath.
 *
 * An “instance” is an entry type as used in one place, renamed: adding an
 * existing entry type to a section or a nested-entry field lets you override
 * its name, handle and description there. Craft models that by cloning the
 * entry type and pointing {@see EntryType::$original} at the real one.
 */
class EntryTypesController extends Controller
{
    public function beforeAction($action): bool
    {
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    /**
     * Redirect target for the hijacked native Settings → Entry Types URL.
     */
    public function actionRedirect(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('discontent/entry-types'));
    }

    public function actionIndex(): Response
    {
        [, , $unusedCount, $instances] = $this->usageIndex();

        $request = Craft::$app->getRequest();
        $initialView = (string)$request->getParam('view', '');

        // Each view is offered only when it has something to show: something
        // unused, something renamed somewhere, or — for the slug and status
        // views — a genuine split, since filtering to “all of them” says
        // nothing. A shared link keeps its own view available regardless.
        $total = 0;
        $withSlug = 0;
        $withStatus = 0;
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            $total++;
            if ($entryType->showSlugField) {
                $withSlug++;
            }
            if ($entryType->showStatusField) {
                $withStatus++;
            }
        }

        $views = [['value' => '', 'label' => Craft::t('discontent', 'Show all')]];
        if ($unusedCount > 0 || $initialView === 'unused') {
            $views[] = ['value' => 'unused', 'label' => Craft::t('discontent', 'Unused')];
        }
        if ($instances !== [] || $initialView === 'instances') {
            $views[] = ['value' => 'instances', 'label' => Craft::t('discontent', 'Has instances')];
        }
        array_push($views, ...ViewOptions::pair(
            Craft::t('discontent', 'Slug'),
            ['slugs', Craft::t('discontent', 'Has slug')],
            ['no-slugs', Craft::t('discontent', 'No slug')],
            $withSlug,
            $total,
            $initialView,
        ), ...ViewOptions::pair(
            Craft::t('discontent', 'Status'),
            ['statuses', Craft::t('discontent', 'Has status')],
            ['no-statuses', Craft::t('discontent', 'No status')],
            $withStatus,
            $total,
            $initialView,
        ));

        return $this->renderTemplate('discontent/entry-types', [
            // Nothing to choose between if "Show all" is the only view.
            'views' => count($views) > 1 ? $views : [],
            // Rendered once for the Slug / Status columns rather than
            // per row (there’s no iconSvg() in Twig).
            'checkIcon' => Cp::iconSvg('check'),
            'initialSearch' => (string)$request->getParam('search', ''),
            'initialFilter' => (string)$request->getParam('usage', ''),
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
        $usage = $request->getParam('usage');
        // The views are mutually exclusive: unused entry types have no instances.
        $view = (string)$request->getParam('view', '');
        $unused = $view === 'unused';
        $showInstances = $view === 'instances';
        $descending = $request->getParam('sort.0.direction') === 'desc';
        $orderBy = $request->getParam('sort.0.field') === '__slot:handle' ? 'handle' : 'name';

        [$usages, $kinds, , $instances] = $this->usageIndex();

        /** @var EntryType[] $entryTypes */
        $entryTypes = Craft::$app->getEntries()->getAllEntryTypes();

        // Usage-kind filter
        if (in_array($usage, ['section', 'matrix', 'ckeditor'], true)) {
            $entryTypes = array_filter($entryTypes, fn(EntryType $et) => !empty($kinds[$et->id][$usage]));
        }

        // Unused filter
        if ($unused) {
            $entryTypes = array_filter($entryTypes, fn(EntryType $et) => empty($usages[$et->id]));
        }

        // The instances view is about the instances, so drop the entry types
        // that don’t have any — otherwise the nested rows are lost in the list.
        if ($showInstances) {
            $entryTypes = array_filter($entryTypes, fn(EntryType $et) => isset($instances[$et->id]));
        }

        // The slug / status views: the ones that show that field to authors,
        // or hide it.
        $entryTypes = match ($view) {
            'slugs' => array_filter($entryTypes, fn(EntryType $et) => $et->showSlugField),
            'no-slugs' => array_filter($entryTypes, fn(EntryType $et) => !$et->showSlugField),
            'statuses' => array_filter($entryTypes, fn(EntryType $et) => $et->showStatusField),
            'no-statuses' => array_filter($entryTypes, fn(EntryType $et) => !$et->showStatusField),
            default => $entryTypes,
        };

        // Search — label + handle, plus any instance’s label or handle, so an
        // entry type can be found by the name it goes by somewhere else.
        if ($search !== '') {
            $term = mb_strtolower($search);
            $entryTypes = array_filter($entryTypes, function(EntryType $et) use ($term, $instances) {
                if (
                    str_contains(mb_strtolower($et->getUiLabel()), $term) ||
                    str_contains(mb_strtolower((string)$et->handle), $term)
                ) {
                    return true;
                }
                foreach ($instances[$et->id] ?? [] as $instance) {
                    if (
                        str_contains(mb_strtolower($instance['label']), $term) ||
                        str_contains(mb_strtolower($instance['handle']), $term)
                    ) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Sort
        $entryTypes = array_values($entryTypes);
        usort($entryTypes, function(EntryType $a, EntryType $b) use ($orderBy) {
            return $orderBy === 'handle'
                ? strcasecmp((string)$a->handle, (string)$b->handle)
                : strcasecmp($a->getUiLabel(), $b->getUiLabel());
        });
        if ($descending) {
            $entryTypes = array_reverse($entryTypes);
        }

        $total = count($entryTypes);
        $paged = array_slice($entryTypes, ($page - 1) * $limit, $limit);

        $tableData = [];
        $arrowIcon = Cp::iconSvg('arrow-turn-down-right');

        foreach ($paged as $entryType) {
            $label = Html::encode($entryType->getUiLabel());
            $typeInstances = $instances[$entryType->id] ?? [];

            $chip = Html::beginTag('div', ['class' => 'inline-chips']) .
                Cp::chipHtml($entryType, [
                    'labelHtml' => Html::a($label, $entryType->getCpEditUrl(), [
                        'class' => ['chip-label', 'cell-bold'],
                    ]),
                ]);
            if ($entryType->description) {
                $chip .= Html::tag('span',
                    Html::decodeDoubles(Markdown::process(Html::encodeInvalidTags(Html::encode($entryType->description)), 'gfm-comment')),
                    ['class' => 'info']);
            }
            // Instances are worth flagging in every view, not just theirs.
            if ($typeInstances !== []) {
                $chip .= Html::tag('span', Craft::t('discontent', '+ {count, number} {count, plural, =1{instance} other{instances}}', [
                    'count' => count($typeInstances),
                ]), ['class' => 'ct-note']);
            }
            $chip .= Html::endTag('div');

            $tableData[] = [
                'id' => $entryType->id,
                'title' => $label,
                'chip' => $chip,
                'handle' => $entryType->handle,
                'usages' => Cp::componentPreviewHtml($usages[$entryType->id] ?? [], ['hyperlink' => true]),
                // Whether an author sees the Slug and Status fields when
                // editing one of these.
                'slug' => $entryType->showSlugField,
                'status' => $entryType->showStatusField,
            ];

            // Instance rows, nested directly under the entry type they rename.
            // They’re ordinary table rows the admin table knows nothing about,
            // so pagination and sorting stay entry-type-level and the nesting
            // rides along. `_showDelete` keeps the delete button off them —
            // they aren’t deletable things.
            if (!$showInstances) {
                continue;
            }

            foreach ($typeInstances as $i => $instance) {
                $owner = $instance['owner'];
                $ownerUrl = $owner instanceof CpEditable ? $owner->getCpEditUrl() : null;

                $tableData[] = [
                    'id' => "$entryType->id-instance-$i",
                    'title' => $instance['label'],
                    'chip' => $this->instanceChipHtml($instance['label'], $ownerUrl, $arrowIcon),
                    'handle' => $instance['handle'],
                    'usages' => Cp::componentPreviewHtml([$owner], ['hyperlink' => true]),
                    // Renaming doesn’t touch these, so an instance shows what
                    // it inherits — it really does hide the slug if its entry
                    // type does.
                    'slug' => $entryType->showSlugField,
                    'status' => $entryType->showStatusField,
                    // Where a double-click takes this row: the section or field
                    // that renamed it.
                    'instance' => [
                        'slideout' => $this->ownerSlideout($owner),
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
     * The first cell for an instance row: the ↳ arrow, then the name it goes by
     * here, linking to the section or field that renamed it.
     *
     * The marker class is what the CSS hooks onto to mute the row — the admin
     * table gives us no way to class a <tr> from its row data.
     */
    private function instanceChipHtml(string $label, ?string $url, string $arrowIcon): string
    {
        $html = Html::tag('div', $arrowIcon, ['class' => ['cp-icon', 'small']]);

        $html .= $url !== null
            ? Html::a(Html::encode($label), $url)
            : Html::tag('span', Html::encode($label));

        return Html::tag('div', $html, ['class' => ['ct-instance', 'flex', 'flex-nowrap', 'gap-s']]);
    }

    /**
     * The CP screen that renamed an entry type, for the double-click slideout.
     *
     * An instance’s name, handle and description belong to the thing using it,
     * so that’s what gets opened — Craft edits them there, on the entry type’s
     * chip in the section or field.
     *
     * @return array{action: string, param: string, id: int}|null
     */
    private function ownerSlideout(mixed $owner): ?array
    {
        return match (true) {
            $owner instanceof Section => [
                'action' => 'sections/edit-section',
                'param' => 'sectionId',
                'id' => $owner->id,
            ],
            $owner instanceof FieldInterface => [
                'action' => 'fields/edit-field',
                'param' => 'fieldId',
                'id' => $owner->id,
            ],
            default => null,
        };
    }

    /**
     * Builds entry-type usage data in one pass.
     *
     * @return array{
     *     0: array<int, array>,
     *     1: array<int, array<string, bool>>,
     *     2: int,
     *     3: array<int, list<array{label: string, handle: string, owner: mixed}>>,
     * }
     *   [usages (id => [Section|Field]), kinds (id => ['section'|'field' => true]),
     *    unusedCount, instances (id => renamed usages)]
     */
    private function usageIndex(): array
    {
        $usages = [];
        $kinds = [];
        $instances = [];

        // Sections
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $usages[$entryType->id][] = $section;
                $kinds[$entryType->id]['section'] = true;
                $this->collectInstance($instances, $entryType, $section);
            }
        }

        // Nested-entry fields, tracked by field type so Matrix and CKEditor
        // entries can be filtered apart.
        $fieldsService = Craft::$app->getFields();
        foreach ($fieldsService->getNestedEntryFieldTypes() as $type) {
            $kind = match ($type) {
                \craft\fields\Matrix::class => 'matrix',
                'craft\\ckeditor\\Field' => 'ckeditor',
                default => 'field',
            };
            /** @var ElementContainerFieldInterface[] $fields */
            $fields = $fieldsService->getFieldsByType($type);
            foreach ($fields as $field) {
                foreach ($field->getFieldLayoutProviders() as $provider) {
                    if ($provider instanceof EntryType) {
                        $usages[$provider->id][] = $field;
                        $kinds[$provider->id][$kind] = true;
                        $this->collectInstance($instances, $provider, $field);
                    }
                }
            }
        }

        $unusedCount = 0;
        foreach (Craft::$app->getEntries()->getAllEntryTypes() as $entryType) {
            if (empty($usages[$entryType->id])) {
                $unusedCount++;
            }
        }

        foreach ($instances as &$typeInstances) {
            usort($typeInstances, fn(array $a, array $b) => strcasecmp($a['label'], $b['label']));
        }

        return [$usages, $kinds, $unusedCount, $instances];
    }

    /**
     * Records an entry type usage as an instance if it’s been renamed there.
     *
     * Craft only sets {@see EntryType::$original} when the usage overrides
     * something, but that includes the entry type’s group — which says nothing
     * about how it reads in the list. So the overridden values are compared
     * against the original directly.
     *
     * @param array<int, list<array>> $instances
     * @param Section|ElementContainerFieldInterface $owner
     */
    private function collectInstance(array &$instances, EntryType $entryType, mixed $owner): void
    {
        $original = $entryType->original;

        if (
            $original === null ||
            (
                $entryType->name === $original->name &&
                $entryType->handle === $original->handle &&
                $entryType->description === $original->description
            )
        ) {
            return;
        }

        $instances[$entryType->id][] = [
            'label' => $entryType->getUiLabel(),
            'handle' => (string)$entryType->handle,
            'owner' => $owner,
        ];
    }
}
