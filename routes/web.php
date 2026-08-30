<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\MediaWikiOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/login', fn () => view('app'))->name('login');

Route::prefix('auth/mediawiki')->group(function () {
    Route::get('/redirect', [MediaWikiOAuthController::class, 'redirect'])->name('oauth.redirect');
    Route::get('/callback', [MediaWikiOAuthController::class, 'callback'])->name('oauth.callback');
});

Route::post('/logout', [MediaWikiOAuthController::class, 'logout'])->name('logout');

Route::get('/{any}', fn () => view('app'))
    ->where('any', '^(?!api|auth|up|build|storage).*$')
    ->name('spa');

Route::get('/', fn () => view('app'))->name('home');
