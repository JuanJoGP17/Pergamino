<?php

use App\Http\Controllers\LogoutController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard;
use App\Livewire\Sheet\Editor;
use App\Models\Template;
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

    // Listado provisional de plantillas: el catálogo completo llega en la Fase 7.
    Route::get('/plantillas', function () {
        return view('templates.index', [
            'templates' => Template::visibleTo(auth()->user())
                ->withCount('sheets')
                ->orderByDesc('is_official')
                ->orderBy('name')
                ->get(),
        ]);
    })->name('templates.index');
});
