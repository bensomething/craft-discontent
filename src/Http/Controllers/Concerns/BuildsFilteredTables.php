<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Controllers\Concerns;

use CraftCms\Cms\Cp\Components\Icon;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Enums\FieldWidth;
use CraftCms\Cms\Ui\Nodes\Field;
use Illuminate\Support\Collection;

use function CraftCms\Cms\t;

/**
 * What the Sections, Entry Types and Fields pages share: reactive filter dropdowns, a
 * table keyed by its filters, server-side paging, and the cells every page draws the
 * same way.
 *
 * A filter change rebuilds the page through the controller's `renderUi()`. Craft's table
 * doesn't refetch when only its `dataUrl` changes, so {@see tableKey()} puts the filter
 * values in the table's uid: a new uid remounts the table, which fetches from the new
 * URL.
 */
trait BuildsFilteredTables
{
    /**
     * A filter dropdown, or nothing when there's only one choice (nothing to filter to).
     *
     * @param  list<array{value: string, label: string}>  $options
     * @return list<Field>
     */
    private function filterField(string $label, string $path, array $options): array
    {
        if (count($options) < 2) {
            return [];
        }

        return [
            Field::make($label, Choice::make($path)->options($options)->withoutPlaceholder()->reactive())
                ->width(FieldWidth::Half),
        ];
    }

    /** @param array<string, string> $filters */
    private function tableKey(string $page, array $filters): string
    {
        $key = implode('-', array_filter($filters)) ?: 'all';

        return "discontent-$page-".preg_replace('/[^a-z0-9-]+/i', '-', $key);
    }

    /**
     * A filter's value from a query string or a refresh, or '' if it isn't one of `$allowed`.
     *
     * @param  array<array-key, mixed>  $input
     * @param  list<string>  $allowed
     */
    private function allowedValue(array $input, string $key, array $allowed): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * One page of a sorted collection, and the pagination Craft's table expects.
     *
     * @template T
     *
     * @param  Collection<int, T>  $items
     * @return array{0: Collection<int, T>, 1: array<string, int|string|null>}
     */
    private function paginate(Collection $items, int $page, int $perPage): array
    {
        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            $items->slice(($page - 1) * $perPage, $perPage)->values(),
            [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'next_page_url' => null,
                'prev_page_url' => null,
                'from' => $total === 0 ? null : ($page - 1) * $perPage + 1,
                'to' => $total === 0 ? null : min($total, $page * $perPage),
            ],
        ];
    }

    /** @return array{icon: string, label: string}|string */
    private function flag(bool $on): array|string
    {
        return $on ? ['icon' => 'check', 'label' => t('Yes', category: 'discontent')] : '—';
    }

    /**
     * A link Craft's table opens in a slideout when its row is double-clicked: it looks
     * for the first `[data-cp-url][data-editable]` in the row. A click still navigates.
     *
     * @param  array<string, mixed>  $options
     */
    private function slideoutLink(string $label, ?string $url, ?string $slideoutUrl = null, array $options = []): string
    {
        if ($url === null && $slideoutUrl === null) {
            return Html::tag('span', Html::encode($label), $options);
        }

        $url ??= $slideoutUrl;
        $slideoutUrl ??= $url;

        return Html::a(Html::encode($label), $url, [
            ...$options,
            'data-cp-url' => $slideoutUrl,
            'data-editable' => true,
        ]);
    }

    /** "+ 2 instances", beside a name. */
    private function instanceNote(int $count): string
    {
        if ($count === 0) {
            return '';
        }

        return Html::tag('span', Html::encode(t('+ {count, number} {count, plural, =1{instance} other{instances}}', [
            'count' => $count,
        ], 'discontent')), ['class' => 'light']);
    }

    /** The first cell of an instance row: the ↳ arrow, then the instance's link. */
    private function instanceName(string $linkHtml): string
    {
        return Html::tag('div',
            Icon::make()->name('arrow-turn-down-right')->toHtml().$linkHtml,
            ['class' => 'flex gap-2 items-center', 'style' => 'padding-inline-start: 0.75rem'],
        );
    }
}
