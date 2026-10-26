<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\TelegramWebhookController;
use App\Livewire\PasteProject;
use App\Livewire\ProjectList;
use App\Livewire\ProjectShow;
use App\Livewire\SettingsPage;
use App\Livewire\StatsPage;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::livewire('/', ProjectList::class)->name('projects.index');
    Route::livewire('/paste', PasteProject::class)->name('paste');
    Route::livewire('/projects/{project}', ProjectShow::class)->name('projects.show');
    Route::livewire('/stats', StatsPage::class)->name('stats');
    Route::livewire('/settings', SettingsPage::class)->name('settings');
});

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('telegram.webhook');
