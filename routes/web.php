<?php

use Illuminate\Support\Facades\Route;
use Kompo\Searchbar\Http\Controllers\SearchStateController;

// Authenticated by default (config searchbar.route-middleware): these requests query the searchables' options
// and records, and were open to guests.
Route::prefix('searchstate')->middleware(config('searchbar.route-middleware', ['web', 'auth']))->group(function() {
    Route::post('open',              [SearchStateController::class, 'openSearch'])->name('searchstate.open');
    Route::post('close',             [SearchStateController::class, 'closeSearch'])->name('searchstate.close');
    Route::post('search',            [SearchStateController::class, 'setSearch'])->name('searchstate.set-search');
    Route::post('clean',             [SearchStateController::class, 'cleanSearch'])->name('searchstate.clean-search');
    // A navbar search used (Enter, a result opened): one of the user's recent searches (searchbarClientJs() beacon).
    Route::post('remember',          [SearchStateController::class, 'rememberSearch'])->name('searchstate.remember');
    Route::post('back',              [SearchStateController::class, 'getBack'])->name('searchstate.get-back');
    Route::post('reset-rules',       [SearchStateController::class, 'resetRules'])->name('searchstate.reset-rules');
    Route::post('select-entity',     [SearchStateController::class, 'selectSearchableEntity'])->name('searchstate.select-entity');
    // Deprecated (nothing renders it): removed in the next release.
    Route::post('add-rule',          [SearchStateController::class, 'addRule'])->name('searchstate.add-rule');
    Route::post('column-chip',       [SearchStateController::class, 'columnChip'])->name('searchstate.column-chip');
    Route::post('delete-rule',       [SearchStateController::class, 'deleteRule'])->name('searchstate.delete-rule');
    Route::post('toggle-section-rule', [SearchStateController::class, 'toggleSectionRule'])->name('searchstate.toggle-section-rule');
    Route::post('toggle-default',    [SearchStateController::class, 'toggleDefaultRule'])->name('searchstate.toggle-default');
    Route::post('set-rule-value',    [SearchStateController::class, 'setRuleValue'])->name('searchstate.set-rule-value');
    Route::post('set-rule-param',    [SearchStateController::class, 'setRuleParam'])->name('searchstate.set-rule-param');
    Route::post('set-inline-value',    [SearchStateController::class, 'setInlineFilterValue'])->name('searchstate.set-inline-value');
    Route::post('cancel-rule-edit',    [SearchStateController::class, 'cancelRuleEdit'])->name('searchstate.cancel-rule-edit');
    Route::post('make-rule-editable',  [SearchStateController::class, 'makeRuleEditable'])->name('searchstate.make-rule-editable');
    // A table's "Views" menu and column headers (HasSearchbarViews, HasSearchbarColumnHeaders).
    Route::post('load-favorite',       [SearchStateController::class, 'loadFavorite'])->name('searchstate.load-favorite');
    Route::post('column-filter',       [SearchStateController::class, 'columnFilter'])->name('searchstate.column-filter');
    Route::post('execute-custom-filterable-function',    [SearchStateController::class, 'executeCustomFilterableFunction'])->name('searchstate.execute-custom-filterable-function');
});

