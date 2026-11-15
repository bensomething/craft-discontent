<?php

namespace bensomething\discontent\helpers;

/**
 * Builds the options for the view dropdowns.
 */
class ViewOptions
{
    /**
     * A Has / No pair of views under one heading (an optgroup), offered only
     * where it splits the list: filtering to all of them, or none, says
     * nothing. Whichever is selected stays offered, so a shared link keeps its
     * own view.
     *
     * Craft’s select macro opens an optgroup at each `optgroup` entry, so pairs
     * go after any ungrouped views.
     *
     * @param array{0: string, 1: string} $has Value and label.
     * @param array{0: string, 1: string} $no Value and label.
     * @return list<array<string, string>>
     */
    public static function pair(string $group, array $has, array $no, int $count, int $total, string $selected): array
    {
        if (($count === 0 || $count === $total) && !in_array($selected, [$has[0], $no[0]], true)) {
            return [];
        }

        return [
            ['optgroup' => $group],
            ['value' => $has[0], 'label' => $has[1]],
            ['value' => $no[0], 'label' => $no[1]],
        ];
    }
}
