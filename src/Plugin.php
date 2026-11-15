<?php

declare(strict_types=1);

namespace bensomething\discontent;

use bensomething\discontent\Http\Middleware\RedirectNativePages;
use bensomething\discontent\Support\EntryTypeUsages;
use bensomething\discontent\Support\FieldLayoutScan;
use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Enums\ControlMode;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Override;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Discontent: filterable Sections, Entry Types and Fields screens, Craft 6 line.
 *
 * 2.x is a native rebuild on Craft's `Ui` builder. See `PLAN.md` for the build order
 * and why 1.x's Yii controllers and Vue admin tables weren't carried over.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '2.0.0';

    public bool $hasCpSettings = true;

    /**
     * Where admin changes are disallowed, the settings screen still opens, read-only,
     * so it's clear how Discontent is set up.
     */
    public bool $hasReadOnlyCpSettings = true;

    /**
     * Discontent always offers a nav item. Craft's own CP Navigation settings rename it,
     * move it or hide it, per user group if need be.
     */
    public bool $hasCpSection = true;

    /**
     * Bound here rather than in `boot()`: Craft loads a plugin's route files before
     * `boot()` runs, so anything they resolve has to be bound already.
     */
    #[Override]
    public function register(): void
    {
        parent::register();

        $this->app->singleton(Settings::class, fn () => $this->getSettings());

        // Built once per request: the Entry Types and Fields pages read them for every row.
        $this->app->scoped(EntryTypeUsages::class);
        $this->app->scoped(FieldLayoutScan::class);
    }

    public function boot(Router $router): void
    {
        $router->pushMiddlewareToGroup('craft.cp', RedirectNativePages::class);
    }

    /**
     * The nav item, with a subnav of the pages. Craft highlights whichever item's URL
     * best matches the current one, so nothing here marks a selection.
     */
    #[Override]
    public function getCpNavItem(): ?NavItem
    {
        // Admins only, like the pages: anyone else given Access Discontent would find
        // a nav item that leads to a 403.
        if (! currentUser()?->isAdmin()) {
            return null;
        }

        return new NavItem()
            ->label((string) $this->name)
            ->href(cp_url('discontent'))
            ->icon('hammer')
            // The same icons as Craft's own Settings subnav, which these pages stand in for.
            ->subnav([
                new NavItem()->label(t('Sections', category: 'discontent'))->href(cp_url('discontent/sections'))->icon('newspaper'),
                new NavItem()->label(t('Entry Types', category: 'discontent'))->href(cp_url('discontent/entry-types'))->icon('files'),
                new NavItem()->label(t('Fields', category: 'discontent'))->href(cp_url('discontent/fields'))->icon('pen-to-square'),
                new NavItem()->label(t('Settings', category: 'discontent'))->href(cp_url('settings/plugins/discontent'))->icon('gear'),
            ]);
    }

    #[Override]
    protected static function createSettings(): ?PluginSettings
    {
        return new Settings;
    }

    /**
     * A setting `config/craft/discontent.php` sets is read-only here, with a warning
     * naming the file: Craft applies the file over the saved value, so editing it here
     * would appear to save and do nothing.
     */
    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        $overrides = Config::get("craft.$this->handle", []);
        $overrides = is_array($overrides) ? $overrides : [];

        $overridable = function (string $setting, Field $field) use ($overrides): Field {
            if (array_key_exists($setting, $overrides)) {
                $field->getControl()?->mode(ControlMode::ReadOnly);
                $field->warning(t('Set by `{setting}` in `config/craft/{handle}.php`, so it can’t be changed here.', [
                    'setting' => $setting,
                    'handle' => $this->handle,
                ], 'discontent'));
            }

            return $field;
        };

        return Ui::make([
            $overridable('overrideNativePages', Field::make(t('Replace Native Pages', category: 'discontent'), Lightswitch::make('overrideNativePages'))
                ->instructions(t('When on, Settings → Sections, Entry Types and Fields use Discontent’s filtering pages. Turn off to use Craft’s own.', category: 'discontent'))),

            $overridable('defaultView', Field::make(t('Default View', category: 'discontent'), Choice::make('defaultView')
                ->options([
                    ['value' => 'sections', 'label' => t('Sections', category: 'discontent')],
                    ['value' => 'entry-types', 'label' => t('Entry Types', category: 'discontent')],
                    ['value' => 'fields', 'label' => t('Fields', category: 'discontent')],
                ])
                ->withoutPlaceholder())
                ->instructions(t('Which page the nav item opens.', category: 'discontent'))),
        ]);
    }
}
