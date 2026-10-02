<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\LogoutController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\SheetPrintController;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Dashboard;
use App\Livewire\Sheet\Editor;
use App\Livewire\Template\Appearance;
use App\Livewire\Template\Builder;
use App\Livewire\Template\Index as TemplateIndex;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel');

// Imágenes subidas (retratos): la firma de la URL es la autorización. Ver
// App\Domain\Media\MediaUrl. Fuera de `auth` para que una hoja compartida por
// enlace (Fase 7) pueda enseñar su retrato.
Route::get('/media/{media}', MediaController::class)->middleware('signed')->name('media.show');

Route::middleware('guest')->group(function () {
    Route::get('/entrar', Login::class)->name('login');
    Route::get('/registro', Register::class)->name('register');
});

Route::middleware('auth')->group(function () {
    Route::post('/salir', LogoutController::class)->name('logout');

    Route::get('/panel', Dashboard::class)->name('dashboard');

    Route::get('/hojas/{sheet}', Editor::class)->name('sheets.edit');
    Route::get('/hojas/{sheet}/imprimir', [SheetPrintController::class, 'show'])->name('sheets.print');
    Route::get('/hojas/{sheet}/pdf', [SheetPrintController::class, 'pdf'])->name('sheets.pdf');
    Route::get('/hojas/{sheet}/exportar', [ExportController::class, 'sheet'])->name('sheets.export');

    Route::get('/plantillas', TemplateIndex::class)->name('templates.index');
    Route::get('/plantillas/{template}/constructor', Builder::class)->name('templates.builder');
    Route::get('/plantillas/{template}/apariencia', Appearance::class)->name('templates.appearance');
    Route::get('/plantillas/{template}/exportar', [ExportController::class, 'template'])->name('templates.export');
});
