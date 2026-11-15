<?php

declare(strict_types=1);

namespace bensomething\discontent\Http\Middleware;

use bensomething\discontent\Settings;
use Closure;
use CraftCms\Cms\User\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;

/**
 * Sends Craft's Settings → Sections, Entry Types and Fields index pages to Discontent's,
 * when Replace Native Pages is on.
 *
 * Pushed onto Craft's `craft.cp` group, so it runs after routing on every CP request
 * and matches Craft's own route names rather than paths: that keeps it to the three
 * index pages (not the edit screens under them) and survives a custom CP trigger.
 */
readonly class RedirectNativePages
{
    /** Craft's index route names, and the Discontent page each one goes to. */
    public const array PAGES = [
        'craft.cp.settings.sections.index' => 'discontent/sections',
        'craft.cp.settings.entry-types.index' => 'discontent/entry-types',
        'craft.cp.settings.fields.index' => 'discontent/fields',
    ];

    public function __construct(private Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $target = self::PAGES[$request->route()?->getName() ?? ''] ?? null;

        if ($target !== null && $this->shouldRedirect($request)) {
            return redirect(cp_url($target));
        }

        return $next($request);
    }

    private function shouldRedirect(Request $request): bool
    {
        if (! $this->settings->overrideNativePages || ! $request->isMethod('GET')) {
            return false;
        }

        // A slideout or a table's data request wants the native screen itself.
        if ($request->wantsJson() || $request->hasHeader('X-Craft-Container-Id')) {
            return false;
        }

        // Discontent's pages are admin-only; anyone else keeps Craft's.
        $user = $request->craftUser();

        return $user instanceof User && $user->isAdmin();
    }
}
