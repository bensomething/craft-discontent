<?php

/**
 * Discontent config file.
 *
 * Publish it into your project with `php artisan vendor:publish --tag=discontent`, which
 * copies it to `config/craft/discontent.php`, and edit it there. Anything set in that
 * file overrides the value saved under Settings → Plugins → Discontent, and the field
 * shows as read-only there.
 *
 * Coming from Discontent 1.x on Craft 5: the file has moved from `config/discontent.php`
 * to `config/craft/discontent.php`, and Craft 5's per-environment keys (`'*'`, `'dev'`,
 * …) aren't read. Use `env()` for values that differ by environment.
 */

return [
    // Send Settings → Sections, Entry Types and Fields to Discontent's pages.
    // 'overrideNativePages' => true,

    // The page the nav item opens: 'sections', 'entry-types' or 'fields'.
    // 'defaultView' => 'fields',
];
