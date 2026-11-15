<?php

namespace bensomething\discontent\controllers;

use bensomething\discontent\helpers\ViewOptions;
use Craft;
use craft\helpers\AdminTable;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\models\Section;
use craft\web\Controller;
use yii\web\Response;

/**
 * The Sections tool: the same list Craft shows at Settings → Sections, plus the
 * URI formats its entries get, a section-type dropdown, a routed/unrouted view
 * dropdown, and search. All filtering happens server-side in
 * {@see actionTableData()}.
 *
 * There’s no unused or instances view here, as there is for fields and entry
 * types: a section is a top-level thing — nothing uses it, and nothing renames
 * it.
 */
class SectionsController extends Controller
{
    public function beforeAction($action): bool
    {
        // Admin-only, but usable even when admin changes are disabled — the
        // tool is read-only (deletes go through Craft’s own admin-gated action).
        $this->requireAdmin(false);
        return parent::beforeAction($action);
    }

    /**
     * Redirect target for the hijacked native Settings → Sections URL, so the
     * canonical /discontent/sections URL is what the user lands on.
     */
    public function actionRedirect(): Response
    {
        return $this->redirect(UrlHelper::cpUrl('discontent/sections'));
    }

    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $initialFilter = (string)$request->getParam('type', '');

        // Only offer the section types that exist here — and don’t offer them
        // at all if they’re all of a kind, since there’d be nothing to filter
        // to. A shared link keeps its own type available regardless.
        $present = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $present[$section->type] = true;
        }

        $types = [];
        foreach ([Section::TYPE_SINGLE, Section::TYPE_CHANNEL, Section::TYPE_STRUCTURE] as $type) {
            if (isset($present[$type]) || $initialFilter === $type) {
                $types[] = ['value' => $type, 'label' => $this->typeOptionLabel($type)];
            }
        }

        // A view is only worth offering when it splits the list — nothing to
        // filter to if every section is routed, or none is. A shared link
        // keeps its own view available regardless.
        $initialView = (string)$request->getParam('view', '');
        $total = 0;
        $routed = 0;
        $versioned = 0;
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $total++;
            if ($this->hasUrls($section)) {
                $routed++;
            }
            if ($section->enableVersioning) {
                $versioned++;
            }
        }

        $views = [['value' => '', 'label' => Craft::t('discontent', 'Show all')]];
        array_push($views, ...ViewOptions::pair(
            Craft::t('discontent', 'URLs'),
            ['urls', Craft::t('discontent', 'Has URLs')],
            ['no-urls', Craft::t('discontent', 'No URLs')],
            $routed,
            $total,
            $initialView,
        ), ...ViewOptions::pair(
            Craft::t('discontent', 'Versioning'),
            ['versioned', Craft::t('discontent', 'Has versioning')],
            ['unversioned', Craft::t('discontent', 'No versioning')],
            $versioned,
            $total,
            $initialView,
        ));

        return $this->renderTemplate('discontent/sections', [
            'sectionTypes' => count($types) > 1 ? $types : [],
            // Nothing to choose between if "Show all" is the only view.
            'views' => count($views) > 1 ? $views : [],
            // Rendered once for the Versioning column rather than per row
            // (there’s no iconSvg() in Twig).
            'checkIcon' => Cp::iconSvg('check'),
            'initialSearch' => (string)$request->getParam('search', ''),
            'initialFilter' => $initialFilter,
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
        $type = (string)$request->getParam('type', '');
        $orderBy = match ($request->getParam('sort.0.field')) {
            '__slot:handle' => 'handle',
            'type' => 'type',
            default => 'name',
        };
        $descending = $request->getParam('sort.0.direction') === 'desc';

        /** @var Section[] $sections */
        $sections = Craft::$app->getEntries()->getAllSections();

        // Type filter
        if (in_array($type, [Section::TYPE_SINGLE, Section::TYPE_CHANNEL, Section::TYPE_STRUCTURE], true)) {
            $sections = array_filter($sections, fn(Section $s) => $s->type === $type);
        }

        // View filter
        $sections = match ((string)$request->getParam('view', '')) {
            'versioned' => array_filter($sections, fn(Section $s) => $s->enableVersioning),
            'unversioned' => array_filter($sections, fn(Section $s) => !$s->enableVersioning),
            'urls' => array_filter($sections, fn(Section $s) => $this->hasUrls($s)),
            'no-urls' => array_filter($sections, fn(Section $s) => !$this->hasUrls($s)),
            default => $sections,
        };

        // Search — name, handle, type label
        if ($search !== '') {
            $term = mb_strtolower($search);
            $sections = array_filter($sections, function(Section $s) use ($term) {
                return str_contains(mb_strtolower($s->getUiLabel()), $term)
                    || str_contains(mb_strtolower((string)$s->handle), $term)
                    || str_contains(mb_strtolower($this->typeLabel($s->type)), $term);
            });
        }

        // Sort
        $sections = array_values($sections);
        usort($sections, function(Section $a, Section $b) use ($orderBy) {
            return match ($orderBy) {
                'handle' => strcasecmp((string)$a->handle, (string)$b->handle),
                'type' => strcasecmp($this->typeLabel($a->type), $this->typeLabel($b->type))
                    ?: strcasecmp($a->getUiLabel(), $b->getUiLabel()),
                default => strcasecmp($a->getUiLabel(), $b->getUiLabel()),
            };
        });
        if ($descending) {
            $sections = array_reverse($sections);
        }

        $total = count($sections);
        $paged = array_slice($sections, ($page - 1) * $limit, $limit);

        $tableData = [];
        foreach ($paged as $section) {
            $label = $section->getUiLabel();
            $tableData[] = [
                'id' => $section->id,
                'title' => $label,
                'name' => $label,
                'url' => $section->getCpEditUrl(),
                'handle' => $section->handle,
                'type' => $this->typeLabel($section->type),
                'versioning' => $section->enableVersioning,
                'urls' => $this->urlsHtml($section),
            ];
        }

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $tableData,
        ]);
    }

    /**
     * Whether a section’s entries are routed anywhere.
     *
     * URLs are a per-site setting, so a section can be routed in one site and
     * not another. Routed in at least one counts as routed — which keeps
     * “without URLs” meaning genuinely unrouted, the useful end of the filter.
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
     * The URI format a section’s entries get, or null when it has none (the
     * table renders its own “No URLs”).
     *
     * Multi-site sections can have a different format per site; the first is
     * shown with a note of how many others there are, rather than a wall of
     * near-identical URIs.
     */
    private function urlsHtml(Section $section): ?string
    {
        $formats = [];
        foreach ($section->getSiteSettings() as $siteSettings) {
            if ($siteSettings->hasUrls && $siteSettings->uriFormat !== null) {
                $formats[$siteSettings->uriFormat] = true;
            }
        }

        if ($formats === []) {
            return null;
        }

        $formats = array_keys($formats);
        // Long formats are cut off with an ellipsis (see .ct-uri), in full on
        // hover, so one can't stretch the table past the page.
        $html = Html::tag('code', Html::encode($formats[0]), [
            'class' => 'ct-uri',
            'title' => $formats[0],
        ]);

        if (count($formats) > 1) {
            $html .= Html::tag('span', Craft::t('discontent', '+ {count, number} more', [
                'count' => count($formats) - 1,
            ]), ['class' => 'ct-note']);
        }

        return Html::tag('span', $html, ['class' => ['flex', 'flex-nowrap', 'gap-s']]);
    }

    /**
     * Craft’s own wording for a section type, as the Type column says it.
     */
    private function typeLabel(?string $type): string
    {
        return match ($type) {
            Section::TYPE_SINGLE => Craft::t('app', 'Single'),
            Section::TYPE_CHANNEL => Craft::t('app', 'Channel'),
            Section::TYPE_STRUCTURE => Craft::t('app', 'Structure'),
            default => '',
        };
    }

    /**
     * The same, plural, as the filter dropdown says it.
     */
    private function typeOptionLabel(string $type): string
    {
        return match ($type) {
            Section::TYPE_SINGLE => Craft::t('app', 'Singles'),
            Section::TYPE_CHANNEL => Craft::t('app', 'Channels'),
            Section::TYPE_STRUCTURE => Craft::t('app', 'Structures'),
            default => $type,
        };
    }
}
