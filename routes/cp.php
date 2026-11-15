<?php

declare(strict_types=1);

use bensomething\discontent\Http\Controllers\EntryTypesController;
use bensomething\discontent\Http\Controllers\FieldsController;
use bensomething\discontent\Http\Controllers\PagesController;
use bensomething\discontent\Http\Controllers\SectionsController;
use CraftCms\Cms\Http\Middleware\RequireAdmin;
use Illuminate\Support\Facades\Route;

// Under the CP trigger, behind Craft's CP auth (`auth`, `can:accessCp`). Admin-only, as
// in 1.x, but open where admin changes are off: the pages only read, and their deletes
// go through Craft's own admin-changes-gated routes.
Route::middleware(RequireAdmin::class)->group(function () {
    Route::get('discontent', [PagesController::class, 'index'])->name('discontent.index');

    Route::get('discontent/sections', [SectionsController::class, 'index'])->name('discontent.sections');
    Route::post('discontent/sections/render-ui', [SectionsController::class, 'renderUi'])->name('discontent.sections.render-ui');
    Route::post('discontent/sections/data', [SectionsController::class, 'data'])->name('discontent.sections.data');

    Route::get('discontent/entry-types', [EntryTypesController::class, 'index'])->name('discontent.entry-types');
    Route::post('discontent/entry-types/render-ui', [EntryTypesController::class, 'renderUi'])->name('discontent.entry-types.render-ui');
    Route::post('discontent/entry-types/data', [EntryTypesController::class, 'data'])->name('discontent.entry-types.data');

    Route::get('discontent/fields', [FieldsController::class, 'index'])->name('discontent.fields');
    Route::post('discontent/fields/render-ui', [FieldsController::class, 'renderUi'])->name('discontent.fields.render-ui');
    Route::post('discontent/fields/data', [FieldsController::class, 'data'])->name('discontent.fields.data');
});
