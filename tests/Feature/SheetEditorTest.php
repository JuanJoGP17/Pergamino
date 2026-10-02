<?php

use App\Domain\Schema\PublishTemplate;
use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\SaveSheet;
use App\Livewire\Sheet\Editor;
use App\Models\Sheet;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->template = makeTemplate([
        ['key' => 'nombre_personaje', 'type' => 'text'],
        ['key' => 'fuerza', 'type' => 'attribute', 'default_value' => ['value' => 10]],
    ]);
    app(PublishTemplate::class)($this->template, $this->user);
    $this->sheet = app(CreateSheet::class)($this->template->fresh(), $this->user);
});

it('muestra los campos de la plantilla', function () {
    Livewire::actingAs($this->user)
        ->test(Editor::class, ['sheet' => $this->sheet])
        ->assertSee('Fuerza')
        ->assertSee('Nombre personaje');
});

it('guarda un valor y recalcula el modificador', function () {
    Livewire::actingAs($this->user)
        ->test(Editor::class, ['sheet' => $this->sheet])
        ->set('data.fuerza', 18)
        ->assertHasNoErrors();

    $sheet = $this->sheet->fresh();

    expect($sheet->data['fuerza'])->toBe(18)
        ->and($sheet->computed['fuerza']['mod'])->toBe(4);
});

it('descarta claves que no están en el esquema', function () {
    app(SaveSheet::class)($this->sheet, [
        'fuerza' => 12,
        'campo_inventado' => 'travesura',
    ], $this->user);

    expect($this->sheet->fresh()->data)->not->toHaveKey('campo_inventado');
});

it('deja una revisión al cambiar un valor', function () {
    app(SaveSheet::class)($this->sheet, ['fuerza' => 16], $this->user);

    expect($this->sheet->revisions()->count())->toBe(1)
        ->and($this->sheet->revisions()->first()->summary)->toContain('fuerza');
});

it('no crea revisión si nada cambió', function () {
    app(SaveSheet::class)($this->sheet, $this->sheet->data, $this->user);

    expect($this->sheet->revisions()->count())->toBe(0);
});

it('impide abrir la hoja de otra persona', function () {
    $intruso = User::factory()->create();

    Livewire::actingAs($intruso)
        ->test(Editor::class, ['sheet' => $this->sheet])
        ->assertForbidden();
});

it('permite ver una hoja pública pero no editarla', function () {
    $this->sheet->update(['visibility' => 'public']);
    $otro = User::factory()->create();

    expect($otro->can('view', $this->sheet))->toBeTrue()
        ->and($otro->can('update', $this->sheet))->toBeFalse();
});

it('marca la hoja como desactualizada cuando la plantilla publica otra versión', function () {
    app(PublishTemplate::class)($this->template->fresh(), $this->user);

    $sheet = Sheet::find($this->sheet->id);
    $sheet->refreshDirtyFlag();

    expect($sheet->fresh()->is_template_dirty)->toBeTrue();
});
