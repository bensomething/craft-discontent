<?php

declare(strict_types=1);

use bensomething\discontent\Http\Controllers\PagesController;
use CraftCms\Cms\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

// Under the CP trigger, behind Craft's CP auth (`auth`, `can:accessCp`). Admin-only, as
// in 1.x, but open where admin changes are off: the pages only read, and their deletes
// go through Craft's own admin-changes-gated routes.
Route::middleware(RequireAdmin::class)->group(function () {
    Route::get('discontent', [PagesController::class, 'index'])->name('discontent.index');
    Route::get('discontent/sections', [PagesController::class, 'sections'])->name('discontent.sections');
    Route::get('discontent/entry-types', [PagesController::class, 'entryTypes'])->name('discontent.entry-types');
    Route::get('discontent/fields', [PagesController::class, 'fields'])->name('discontent.fields');
});
