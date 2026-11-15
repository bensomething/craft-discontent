<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Controllers;

use bensomething\discontent\Plugin;
use bensomething\discontent\Settings;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Ui;
use Illuminate\Http\RedirectResponse;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * The landing redirect, and a placeholder for the Sections page until step 5 give
 * each its own controller (see PLAN.md).
 */
readonly class PagesController
{
    public function __construct(private Settings $settings) {}

    /** `/discontent` lands on the configured default view, so its subnav item lights up. */
    public function index(): RedirectResponse
    {
        $view = in_array($this->settings->defaultView, Settings::VIEWS, true)
            ? $this->settings->defaultView
            : 'fields';

        return redirect(cp_url("discontent/$view"));
    }

    public function sections(): CpScreenResponse
    {
        return $this->placeholder(t('Sections', category: 'discontent'));
    }

    private function placeholder(string $title): CpScreenResponse
    {
        return new CpScreenResponse()
            ->title($title)
            ->addCrumb((string) Plugin::getInstance()->name, 'discontent')
            ->ui(Ui::make([
                Callout::make('discontent-placeholder', t('This page is being rebuilt for Craft 6.', category: 'discontent')),
            ]));
    }
}
