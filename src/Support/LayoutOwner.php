<?php

declare(strict_types=1);

namespace bensomething\discontent\Support;

use CraftCms\Cms\Component\Contracts\Chippable;

/** The thing a field layout belongs to: an entry type, a volume, the users layout, … */
final readonly class LayoutOwner
{
    public function __construct(
        public string $label,
        /** Null for layouts with no provider, which can be named but not linked. */
        public ?Chippable $provider = null,
        public ?string $url = null,
        /** Where a double-click goes: only screens Craft can open in a slideout. */
        public ?string $slideoutUrl = null,
    ) {}
}
