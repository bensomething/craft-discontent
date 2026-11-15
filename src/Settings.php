<?php

declare(strict_types=1);

namespace bensomething\discontent;

use CraftCms\Cms\Plugin\PluginSettings;
use Override;

/**
 * Discontent's settings, edited under Settings → Plugins → Discontent or in
 * `config/craft/discontent.php`. Two of 1.x's four: Plugin Name and Show in Main
 * Navigation are retired for Craft's own CP Navigation settings, which rename and hide
 * nav items. Upgraded sites' saved values for them are ignored.
 */
class Settings extends PluginSettings
{
    /** The pages Discontent provides, in nav order: also the values of `defaultView`. */
    public const array VIEWS = ['sections', 'entry-types', 'fields'];

    /** Which page the top-level `/discontent` URL lands on: one of {@see VIEWS}. */
    public string $defaultView = 'fields';

    /**
     * Whether Settings → Sections, Entry Types and Fields send people to Discontent's
     * pages. Off, Craft's own pages are used and Discontent's are only reached directly.
     */
    public bool $overrideNativePages = true;

    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'defaultView' => ['required', 'string', 'in:'.implode(',', self::VIEWS)],
            'overrideNativePages' => ['boolean'],
        ]);
    }
}
