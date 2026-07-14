<?php

namespace bensomething\discontent\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Public-facing name of the plugin (Settings → Plugins list, nav item,
     * page titles). Falls back to “Discontent” when blank.
     */
    public string $pluginName = 'Discontent';

    /**
     * Which page the top-level /discontent URL lands on.
     * One of: 'fields', 'entry-types'.
     */
    public string $defaultView = 'fields';

    /**
     * Whether to replace Craft’s native Settings → Fields / Entry Types index
     * pages with the enhanced filtering versions. When off, Craft’s native
     * pages are used and the tool stays dormant.
     */
    public bool $overrideNativePages = true;

    /**
     * Whether to add a nav item to the main CP nav (with a Fields / Entry
     * Types / Settings subnav). Off by default — the tool is reached through
     * the plugin’s gear in Settings → Plugins.
     */
    public bool $showInMainNav = false;

    protected function defineRules(): array
    {
        return [
            [['pluginName'], 'string'],
            [['overrideNativePages', 'showInMainNav'], 'boolean'],
            [['defaultView'], 'in', 'range' => ['fields', 'entry-types']],
        ];
    }

    public function getName(): string
    {
        return trim($this->pluginName) !== '' ? trim($this->pluginName) : 'Discontent';
    }
}
