<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AuthentikOidcController;
use Illuminate\Support\Facades\Route;

Route::get('/login', fn () => view('app'))->name('login');

Route::prefix('auth/oidc')->group(function () {
    Route::get('/redirect', [AuthentikOidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('/callback', [AuthentikOidcController::class, 'callback'])->name('oidc.callback');
});

Route::post('/logout', [AuthentikOidcController::class, 'logout'])->name('logout');

Route::get('/{any}', fn () => view('app'))
    ->where('any', '^(?!api|auth|up|build|storage).*$')
    ->name('spa');

Route::get('/', fn () => view('app'))->name('home');
