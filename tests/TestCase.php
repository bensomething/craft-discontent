<?php

declare(strict_types=1);

namespace bensomething\discontent\tests;

use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use CraftCms\Cms\User\Models\User as UserModel;
use Override;

/**
 * Boots Craft 6 on an in-memory SQLite database, runs its install, and installs
 * Discontent from this package's composer.json, through Craft's own plugin test case.
 */
abstract class TestCase extends PluginTestCase
{
    #[Override]
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite.database', ':memory:');
    }

    /** Signs in as the admin Craft's install created. */
    protected function signIn(): UserModel
    {
        $user = UserModel::first();
        $this->actingAs($user, 'craft');

        return $user;
    }
}
