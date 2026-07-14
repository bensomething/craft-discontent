<?php

namespace bensomething\contenttools\controllers;

use Craft;
use craft\base\ElementContainerFieldInterface;
use craft\helpers\AdminTable;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Markdown;
use craft\helpers\UrlHelper;
use craft\models\EntryType;
use craft\web\Controller;
use yii\web\Response;

/**
 * The Entry Types tool: Craft’s Settings → Entry Types list plus a usage filter
 * (used by a Section / by a Matrix field) and an “Unused” toggle.
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
        return $this->redirect(UrlHelper::cpUrl('content-tools/entry-types'));
    }

    public function actionIndex(): Response
    {
        [, , $unusedCount] = $this->usageIndex();

        $request = Craft::$app->getRequest();
        $initialUnused = (bool)$request->getParam('unused');

        return $this->renderTemplate('content-tools/entry-types', [
            'showUnused' => $unusedCount > 1 || $initialUnused,
            'initialSearch' => (string)$request->getParam('search', ''),
            'initialFilter' => (string)$request->getParam('usage', ''),
            'initialUnused' => $initialUnused,
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
        $unused = (bool)$request->getParam('unused');
        $descending = $request->getParam('sort.0.direction') === 'desc';
        $orderBy = $request->getParam('sort.0.field') === '__slot:handle' ? 'handle' : 'name';

        [$usages, $kinds] = $this->usageIndex();

        /** @var EntryType[] $entryTypes */
        $entryTypes = Craft::$app->getEntries()->getAllEntryTypes();

        // Usage-kind filter
        if ($usage === 'section' || $usage === 'field') {
            $entryTypes = array_filter($entryTypes, fn(EntryType $et) => !empty($kinds[$et->id][$usage]));
        }

        // Unused filter
        if ($unused) {
            $entryTypes = array_filter($entryTypes, fn(EntryType $et) => empty($usages[$et->id]));
        }

        // Search — label + handle
        if ($search !== '') {
            $term = mb_strtolower($search);
            $entryTypes = array_filter($entryTypes, function(EntryType $et) use ($term) {
                return str_contains(mb_strtolower($et->getUiLabel()), $term)
                    || str_contains(mb_strtolower((string)$et->handle), $term);
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
        foreach ($paged as $entryType) {
            $label = Html::encode($entryType->getUiLabel());
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
            $chip .= Html::endTag('div');

            $tableData[] = [
                'id' => $entryType->id,
                'title' => $label,
                'chip' => $chip,
                'handle' => $entryType->handle,
                'usages' => Cp::componentPreviewHtml($usages[$entryType->id] ?? [], ['hyperlink' => true]),
            ];
        }

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $tableData,
        ]);
    }

    /**
     * Builds entry-type usage data in one pass.
     *
     * @return array{0: array<int, array>, 1: array<int, array<string, bool>>, 2: int}
     *   [usages (id => [Section|Field]), kinds (id => ['section'|'field' => true]), unusedCount]
     */
    private function usageIndex(): array
    {
        $usages = [];
        $kinds = [];

        // Sections
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $usages[$entryType->id][] = $section;
                $kinds[$entryType->id]['section'] = true;
            }
        }

        // Nested-entry fields (Matrix, CKEditor, …)
        $fieldsService = Craft::$app->getFields();
        foreach ($fieldsService->getNestedEntryFieldTypes() as $type) {
            /** @var ElementContainerFieldInterface[] $fields */
            $fields = $fieldsService->getFieldsByType($type);
            foreach ($fields as $field) {
                foreach ($field->getFieldLayoutProviders() as $provider) {
                    if ($provider instanceof EntryType) {
                        $usages[$provider->id][] = $field;
                        $kinds[$provider->id]['field'] = true;
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

        return [$usages, $kinds, $unusedCount];
    }
}
