<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Controllers;

use bensomething\discontent\Settings;
use Illuminate\Http\RedirectResponse;

use function CraftCms\Cms\cp_url;

/** `/discontent` itself, which has no page of its own. */
readonly class PagesController
{
    public function __construct(private Settings $settings) {}

    /** Lands on the configured default view, so its subnav item lights up. */
    public function index(): RedirectResponse
    {
        $view = in_array($this->settings->defaultView, Settings::VIEWS, true)
            ? $this->settings->defaultView
            : 'fields';

        return redirect(cp_url("discontent/$view"));
    }
}
