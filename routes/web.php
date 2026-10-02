<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * The resume download. Mounted by the HOST, because serving the file is the
 * host's half of `resume_path` -- see ResumeDownloadController for why the
 * obvious implementation (a public disk and Storage::url) is the wrong one.
 */
Route::middleware(['web', 'auth'])
    ->get('/applications/{application}/resume', \App\Http\Controllers\ResumeDownloadController::class)
    ->name('applications.resume');

/*
 * A named `login` route, because `auth` middleware REDIRECTS an unauthenticated
 * request and needs somewhere to send it. Without this the guest case is a 500
 * ("Route [login] not defined") rather than a redirect -- which reads as a bug in
 * the resume route and is not one. A host with real auth scaffolding already has
 * this; a bare `laravel new` plus a package does not, and that gap is exactly the
 * kind of thing a reference consumer exists to have already hit.
 */
Route::get('/login', fn () => response('sign in', 200))->name('login');
