<?php

use App\Http\Controllers\AlertFeedController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\TelegramWebhookController;
use App\Livewire\OpportunityList;
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
    Route::livewire('/opportunities', OpportunityList::class)->name('opportunities'); // shadow mode, experimental

    Route::get('/push/key', [PushController::class, 'key'])->name('push.key');
    Route::post('/push/subscribe', [PushController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [PushController::class, 'unsubscribe'])->name('push.unsubscribe');
    Route::post('/push/test', [PushController::class, 'test'])->middleware('throttle:10,1')->name('push.test');
});

// Token-protected, read-only list of pushed projects (Settings > Browser push > feed token).
Route::get('/alerts/feed', AlertFeedController::class)
    ->middleware('throttle:120,1')
    ->name('alerts.feed');

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('telegram.webhook');
