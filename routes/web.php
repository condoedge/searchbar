<?php

use Illuminate\Support\Facades\Route;
use Kompo\Searchbar\Http\Controllers\SearchStateController;

Route::prefix('searchstate')->middleware('web')->group(function() {
    Route::post('open',              [SearchStateController::class, 'openSearch'])->name('searchstate.open');
    Route::post('close',             [SearchStateController::class, 'closeSearch'])->name('searchstate.close');
    Route::post('search',            [SearchStateController::class, 'setSearch'])->name('searchstate.set-search');
    Route::post('clean',             [SearchStateController::class, 'cleanSearch'])->name('searchstate.clean-search');
    Route::post('back',              [SearchStateController::class, 'getBack'])->name('searchstate.get-back');
    Route::post('select-entity',     [SearchStateController::class, 'selectSearchableEntity'])->name('searchstate.select-entity');
    Route::post('add-rule',          [SearchStateController::class, 'addRule'])->name('searchstate.add-rule');
    Route::post('delete-rule',       [SearchStateController::class, 'deleteRule'])->name('searchstate.delete-rule');
    Route::post('toggle-default',    [SearchStateController::class, 'toggleDefaultRule'])->name('searchstate.toggle-default');
    Route::post('set-rule-value',    [SearchStateController::class, 'setRuleValue'])->name('searchstate.set-rule-value');
    Route::post('set-rule-param',    [SearchStateController::class, 'setRuleParam'])->name('searchstate.set-rule-param');
    Route::post('execute-custom-filterable-function',    [SearchStateController::class, 'executeCustomFilterableFunction'])->name('searchstate.execute-custom-filterable-function');
});

