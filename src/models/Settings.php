<?php

namespace bensomething\contenttools\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Which page the top-level /content-tools URL lands on.
     * One of: 'fields', 'entry-types'.
     */
    public string $defaultView = 'fields';

    /**
     * Whether to replace Craft’s native Settings → Fields (and Entry Types,
     * once available) index pages with the enhanced filtering versions. When
     * off, Craft’s native pages are used and the tool stays dormant.
     */
    public bool $overrideNativePages = true;

    /**
     * Whether to add a “Content Tools” item to the main CP nav (with a
     * Fields / Entry Types / Settings subnav). Off by default — the tool is
     * reached through the plugin’s gear in Settings → Plugins.
     */
    public bool $showInMainNav = false;

    protected function defineRules(): array
    {
        return [
            [['overrideNativePages', 'showInMainNav'], 'boolean'],
            [['defaultView'], 'in', 'range' => ['fields', 'entry-types']],
        ];
    }
}
