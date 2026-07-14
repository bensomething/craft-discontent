<?php

namespace bensomething\contenttools\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * Whether to add a “Content Tools” item to the main CP nav (with a
     * Fields / Entry Types / Settings subnav). Off by default — the tool is
     * reached through the plugin’s gear in Settings → Plugins.
     */
    public bool $showInMainNav = false;

    protected function defineRules(): array
    {
        return [
            [['showInMainNav'], 'boolean'],
        ];
    }
}
