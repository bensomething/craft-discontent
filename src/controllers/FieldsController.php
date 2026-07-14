<?php

namespace bensomething\contenttools\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\base\Iconic;
use craft\fields\MissingField;
use craft\helpers\AdminTable;
use craft\helpers\Cp;
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
        // tool is read-only.
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    public function actionIndex(): Response
    {
        $types = [];
        foreach (Craft::$app->getFields()->getAllFieldTypes() as $class) {
            /** @var string|FieldInterface $class */
            $types[] = ['value' => $class, 'label' => $class::displayName()];
        }
        usort($types, fn($a, $b) => strcasecmp($a['label'], $b['label']));

        return $this->renderTemplate('content-tools/fields', [
            'title' => Craft::t('content-tools', 'Fields'),
            'selectedTab' => 'fields',
            'fieldTypes' => $types,
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
