<?php

namespace bensomething\contenttools\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\base\Iconic;
use craft\fields\MissingField;
use craft\helpers\AdminTable;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

/**
 * The Fields tool: the same list Craft shows at Settings → Fields, plus a
 * field-type dropdown and an “Unused only” toggle. All filtering happens
 * server-side in {@see actionTableData()}.
 */
class FieldsController extends Controller
{
    public function beforeAction($action): bool
    {
        // Admin-only, but usable even when admin changes are disabled — the
        // tool is read-only (deletes go through Craft’s own admin-gated action).
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    /**
     * Redirect target for the hijacked native Settings → Fields URL, so the
     * canonical /content-tools/fields URL is what the user lands on.
     */
    public function actionRedirect(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('content-tools/fields'));
    }

    public function actionIndex(): Response
    {
        $fieldsService = Craft::$app->getFields();

        // Only list field types that are actually in use, and count unused
        // fields — both in a single pass over the fields.
        $usedTypes = [];
        $unusedCount = 0;
        foreach ($fieldsService->getAllFields() as $field) {
            $usedTypes[$field::class] ??= $field->displayName();
            if (empty($fieldsService->findFieldUsages($field))) {
                $unusedCount++;
            }
        }

        $types = [];
        foreach ($usedTypes as $value => $label) {
            $types[] = ['value' => $value, 'label' => $label];
        }
        usort($types, fn($a, $b) => strcasecmp($a['label'], $b['label']));

        // Reflect any shared-link filters back into the toolbar controls.
        $request = Craft::$app->getRequest();
        $initialUnused = (bool)$request->getParam('unused');

        return $this->renderTemplate('content-tools/fields', [
            'fieldTypes' => $types,
            // Only surface the "Unused" toggle when there’s more than one
            // unused field — or when a shared link has it active.
            'showUnused' => $unusedCount > 1 || $initialUnused,
            'initialSearch' => (string)$request->getParam('search', ''),
            'initialFilter' => (string)$request->getParam('type', ''),
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
        $type = $request->getParam('type');
        $unused = (bool)$request->getParam('unused');
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

        // Search filter — name, handle, type label, instructions
        if ($search !== '') {
            $term = mb_strtolower($search);
            $fields = array_filter($fields, function(FieldInterface $f) use ($term) {
                return str_contains(mb_strtolower((string)$f->name), $term)
                    || str_contains(mb_strtolower((string)$f->handle), $term)
                    || str_contains(mb_strtolower((string)$f->displayName()), $term)
                    || str_contains(mb_strtolower((string)$f->instructions), $term);
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
        foreach ($paged as $field) {
            $count = $usageCounts[$field->id] ?? 0;
            $tableData[] = [
                'id' => $field->id,
                'title' => Craft::t('site', $field->name),
                'url' => $field->getCpEditUrl(),
                'handle' => $field->handle,
                'type' => [
                    'isMissing' => $field instanceof MissingField,
                    'label' => $field instanceof MissingField ? $field->expectedType : $field->displayName(),
                    'icon' => Cp::iconSvg($field instanceof Iconic ? $field->getIcon() : $field::icon()),
                ],
                'usages' => $count > 0
                    ? Craft::t('app', '{count, number} {count, plural, =1{layout} other{layouts}}', ['count' => $count])
                    : null,
            ];
        }

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $tableData,
        ]);
    }
}
