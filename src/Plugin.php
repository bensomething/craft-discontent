<?php

declare(strict_types=1);

namespace bensomething\discontent;

use CraftCms\Cms\Plugin\Plugin as BasePlugin;

/**
 * Discontent: filterable Sections, Entry Types and Fields screens, Craft 6 line.
 *
 * 2.x is a native rebuild on Craft's `Ui` builder. See `PLAN.md` for the build order
 * and why 1.x's Yii controllers and Vue admin tables weren't carried over.
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '2.0.0';
}
