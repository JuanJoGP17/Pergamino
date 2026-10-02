<?php

use App\Http\Controllers\LogoutController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard;
use App\Livewire\Sheet\Editor;
use App\Livewire\Template\Builder;
use App\Livewire\Template\Index as TemplateIndex;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', Login::class)->name('login');
    Route::get('/registro', Register::class)->name('register');
});

Route::middleware('auth')->group(function () {
    Route::post('/salir', LogoutController::class)->name('logout');

    Route::get('/panel', Dashboard::class)->name('dashboard');

    Route::get('/hojas/{sheet}', Editor::class)->name('sheets.edit');

    Route::get('/plantillas', TemplateIndex::class)->name('templates.index');
    Route::get('/plantillas/{template}/constructor', Builder::class)->name('templates.builder');
});
