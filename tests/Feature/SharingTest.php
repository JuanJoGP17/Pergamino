<?php

use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\SharingException;
use App\Domain\Sheet\SheetSharing;
use App\Livewire\Sheet\Editor;
use App\Models\SheetShare;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\OfficialTemplateSeeder;
use Livewire\Livewire;

/**
 * Compartir hojas por enlace o con una persona (Fase 7).
 */
beforeEach(function () {
    $this->owner = User::factory()->create();
    $this->friend = User::factory()->create(['email' => 'amiga@ejemplo.com']);
    (new OfficialTemplateSeeder)->install('fate-basico');
    $this->sheet = app(CreateSheet::class)(Template::where('slug', 'fate-basico')->sole(), $this->owner, 'Zara');
    $this->sharing = new SheetSharing;
});

it('un enlace de lectura se ve sin entrar, en solo lectura y con PDF', function () {
    $link = $this->sharing->createLink($this->sheet, $this->owner, 'view');

    expect($link->token)->toMatch('/^[0-9a-f]{64}$/');   // 32 bytes aleatorios (§10)

    $this->get(route('shared.show', $link->token))
        ->assertOk()
        ->assertSee('Zara')
        ->assertSee('solo lectura')
        ->assertDontSee('Volver a la hoja');

    expect($this->get(route('shared.pdf', $link->token))->assertOk()->getContent())->toStartWith('%PDF-');
});

it('un enlace caducado, revocado o inventado es un 404', function () {
    $expired = $this->sharing->createLink($this->sheet, $this->owner, 'view', days: 1);
    $expired->update(['expires_at' => now()->subMinute()]);
    $revoked = $this->sharing->createLink($this->sheet, $this->owner, 'view');
    $this->sharing->revoke($this->sheet, $this->owner, $revoked->id);

    $this->get(route('shared.show', $expired->token))->assertNotFound();
    $this->get(route('shared.show', $revoked->token))->assertNotFound();
    $this->get(route('shared.show', str_repeat('a', 64)))->assertNotFound();
    $this->get('/compartida/corto')->assertNotFound();
});

it('un enlace de edición pide entrar y luego da permiso para editar', function () {
    $link = $this->sharing->createLink($this->sheet, $this->owner, 'edit', days: 7);

    $this->get(route('shared.show', $link->token))->assertRedirect(route('login'));

    $this->actingAs($this->friend)->get(route('shared.show', $link->token))->assertRedirect(route('sheets.edit', $this->sheet));

    $grant = SheetShare::where('sheet_id', $this->sheet->id)->where('user_id', $this->friend->id)->sole();
    expect($grant->ability)->toBe('edit')
        ->and($grant->expires_at->toDateString())->toBe($link->expires_at->toDateString())
        ->and($this->friend->can('update', $this->sheet->fresh()))->toBeTrue();
});

it('comparte con una persona por su correo, y solo el dueño comparte', function () {
    $this->sharing->shareWith($this->sheet, $this->owner, '  AMIGA@ejemplo.com ', 'view');

    expect($this->friend->can('view', $this->sheet->fresh()))->toBeTrue()
        ->and($this->friend->can('update', $this->sheet->fresh()))->toBeFalse()
        ->and(fn () => $this->sharing->shareWith($this->sheet, $this->owner, 'nadie@ejemplo.com', 'view'))->toThrow(SharingException::class, 'No hay nadie')
        ->and(fn () => $this->sharing->createLink($this->sheet, $this->friend, 'view'))->toThrow(SharingException::class, 'Solo quien creó');
});

it('quien solo puede ver abre el editor en solo lectura', function () {
    $this->sharing->shareWith($this->sheet, $this->owner, 'amiga@ejemplo.com', 'view');

    Livewire::actingAs($this->friend)->test(Editor::class, ['sheet' => $this->sheet->fresh()])
        ->assertSee('Solo lectura')
        ->assertSeeHtml('<fieldset class="m-0 min-w-0 space-y-6 border-0 p-0" disabled')
        ->assertDontSee('Compartir')
        ->set('data.nombre_personaje', 'Hackeado')
        ->assertForbidden();
});

it('el panel de compartir crea enlaces y da y quita accesos', function () {
    $editor = Livewire::actingAs($this->owner)->test(Editor::class, ['sheet' => $this->sheet])
        ->assertSee('Compartir')
        ->set('linkAbility', 'view')->set('linkDays', '7')->call('createShareLink')
        ->set('shareEmail', 'amiga@ejemplo.com')->set('shareAbility', 'edit')->call('shareWithUser')
        ->assertSee('Enlace de lectura')
        ->assertSee($this->friend->displayName());

    expect(SheetShare::where('sheet_id', $this->sheet->id)->count())->toBe(2);

    $editor->call('revokeShare', SheetShare::whereNotNull('user_id')->value('id'));
    expect($this->friend->can('update', $this->sheet->fresh()))->toBeFalse();

    $editor->set('shareEmail', 'nadie@x.com')->call('shareWithUser')->assertSee('No hay nadie con ese correo');
});
