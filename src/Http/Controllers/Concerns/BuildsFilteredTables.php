<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Controllers\Concerns;

use CraftCms\Cms\Cp\Components\Icon;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Text;
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
                ->labelSrOnly()
                ->width(FieldWidth::Quarter),
        ];
    }

    /**
     * A Has / No pair of views under one heading (an optgroup), offered only where it
     * splits the list: filtering to all of them, or none, says nothing. Whichever is
     * selected stays offered, so a shared link keeps its own view.
     *
     * @param  array{0: string, 1: string}  $has  Value and label.
     * @param  array{0: string, 1: string}  $no  Value and label.
     * @return list<array{value: string, label: string, group: string}>
     */
    private function viewPair(string $group, array $has, array $no, int $count, int $total, string $selected): array
    {
        if (($count === 0 || $count === $total) && ! in_array($selected, [$has[0], $no[0]], true)) {
            return [];
        }

        return [
            ['value' => $has[0], 'label' => $has[1], 'group' => $group],
            ['value' => $no[0], 'label' => $no[1], 'group' => $group],
        ];
    }

    /**
     * The search box, first in the toolbar row with the filters beside it. It stands in
     * for the table's own search, which can't share a row with anything but Craft's
     * status filter: as a reactive control, its value joins the filters in the table's
     * `dataUrl` and uid.
     *
     * @param  int  $filterCount  How many filter dropdowns follow, to fill the row.
     */
    private function searchField(int $filterCount): Field
    {
        return Field::make(t('Search', category: 'discontent'), Text::make('search')
            ->placeholder(t('Search', category: 'discontent'))
            ->inputType('search')
            ->reactive())
            ->labelSrOnly()
            ->width(match ($filterCount) {
                0 => FieldWidth::Full,
                1 => FieldWidth::ThreeQuarters,
                default => FieldWidth::Half,
            });
    }

    /**
     * The table's uid: the filter values, readable, plus a hash of any search, so each
     * combination remounts the table.
     *
     * @param  array<string, string>  $filters
     */
    private function tableKey(string $page, array $filters): string
    {
        $search = $filters['search'] ?? '';
        unset($filters['search']);

        $key = implode('-', array_filter($filters)) ?: 'all';

        if ($search !== '') {
            $key .= '-q'.substr(md5($search), 0, 8);
        }

        return "discontent-$page-".preg_replace('/[^a-z0-9-]+/i', '-', $key);
    }

    /**
     * The search from a query string or a refresh: trimmed, and capped.
     *
     * @param  array<array-key, mixed>  $input
     */
    private function searchValue(array $input): string
    {
        $value = $input['search'] ?? null;

        return is_string($value) ? mb_substr(trim($value), 0, 255) : '';
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

    /**
     * A tick, or a faint dash that's there to hold the column's shape rather than to be
     * read. Screen readers get "No" in its place.
     *
     * @return array{icon: string, label: string}|array{html: string}
     */
    private function flag(bool $on): array
    {
        if ($on) {
            return ['icon' => 'check', 'label' => t('Yes', category: 'discontent')];
        }

        return $this->faintDash(t('No', category: 'discontent'));
    }

    /**
     * A faint dash for an empty cell, there to hold the column's shape rather than to be
     * read. Screen readers get `$spoken` in its place.
     *
     * @return array{html: string}
     */
    private function faintDash(string $spoken): array
    {
        return ['html' => Html::tag('span', '—', ['class' => 'discontent-faint', 'aria-hidden' => 'true'])
            .Html::tag('span', Html::encode($spoken), ['class' => 'sr-only'])];
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

    /**
     * "+ 2 instances", beside a name, linking to `$url`: the page filtered to Has
     * instances and searched for the handle, so the instances are listed under it.
     */
    private function instanceNote(int $count, string $url): string
    {
        if ($count === 0) {
            return '';
        }

        return Html::a(Html::encode(t('+ {count, number} {count, plural, =1{instance} other{instances}}', [
            'count' => $count,
        ], 'discontent')), $url, ['class' => 'light']);
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
