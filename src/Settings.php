<?php

declare(strict_types=1);

namespace bensomething\discontent;

use CraftCms\Cms\Plugin\PluginSettings;
use Override;

/**
 * Discontent's settings, edited under Settings → Plugins → Discontent or in
 * `config/craft/discontent.php`. The same four as 1.x, so saved settings carry over.
 */
class Settings extends PluginSettings
{
    /** The pages Discontent provides, in nav order: also the values of `defaultView`. */
    public const array VIEWS = ['sections', 'entry-types', 'fields'];

    public const string DEFAULT_NAME = 'Discontent';

    /**
     * Public-facing name: the nav item and page titles. Blank means "Discontent".
     * Accepts null because Laravel turns an emptied text input into null.
     */
    public string $pluginName = self::DEFAULT_NAME {
        set(?string $value) {
            $this->pluginName = trim((string) $value);
        }
    }

    /** Which page the top-level `/discontent` URL lands on: one of {@see VIEWS}. */
    public string $defaultView = 'fields';

    /**
     * Whether Settings → Sections, Entry Types and Fields send people to Discontent's
     * pages. Off, Craft's own pages are used and Discontent's are only reached directly.
     */
    public bool $overrideNativePages = true;

    /** Whether Discontent gets its own main-nav item, with a subnav of its pages. */
    public bool $showInMainNav = false;

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'pluginName' => ['nullable', 'string', 'max:255'],
            'defaultView' => ['required', 'string', 'in:'.implode(',', self::VIEWS)],
            'overrideNativePages' => ['boolean'],
            'showInMainNav' => ['boolean'],
        ]);
    }

    /** The name to show: {@see $pluginName}, or the default when it's blank. */
    public function getName(): string
    {
        return $this->pluginName !== '' ? $this->pluginName : self::DEFAULT_NAME;
    }
}
