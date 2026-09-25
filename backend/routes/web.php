<?php

use App\Http\Controllers\Auth\KeycloakController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::controller(KeycloakController::class)->prefix('auth/keycloak')->group(function () {
    Route::get('redirect', 'redirect')->name('keycloak.redirect');
    Route::get('callback', 'callback')->name('keycloak.callback');
});
