<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Vendor\LocaleJsonMerger\Http\Controllers\LocaleJsonController;

Route::post('/check', [LocaleJsonController::class, 'check'])->name('locale-json.check');
Route::post('/merge', [LocaleJsonController::class, 'merge'])->name('locale-json.merge');
