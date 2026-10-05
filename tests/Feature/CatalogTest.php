<?php

use App\Domain\Builder\TemplateEditor;
use App\Domain\Catalog\Catalog;
use App\Domain\Catalog\CatalogException;
use App\Domain\Schema\PublishTemplate;
use App\Domain\Sheet\CreateSheet;
use App\Livewire\Catalog\Index;
use App\Livewire\Catalog\Show;
use App\Livewire\Template\Builder;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\OfficialTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Catálogo y comunidad (Fase 7): buscar, me gusta, instalaciones, clonar y
 * reportar.
 */
beforeEach(function () {
    // El primer usuario es el autor de las oficiales; quien navega, otro.
    $this->author = User::factory()->create();
    (new OfficialTemplateSeeder)->run();
    $this->user = User::factory()->create();
    $this->catalog = new Catalog;
});

it('trae las seis plantillas oficiales del plan, construidas como datos', function () {
    $official = Template::where('is_official', true)->orderBy('name')->get();

    expect($official->pluck('slug')->sort()->values()->all())
        ->toBe(['blades-in-the-dark', 'dnd-5e', 'fate-basico', 'generico', 'pathfinder-1e', 'vampiro-v5'])
        ->and($official->every(fn ($t) => $t->visibility === 'public' && $t->isPublished()))->toBeTrue();
});

it('busca sin distinguir mayúsculas y filtra por línea de juego', function () {
    $names = fn (string $q, string $line = '') => $this->catalog->search($q, $line)->pluck('name')->sort()->values()->all();

    expect($names('VAMPIRO'))->toBe(['Vampiro: La Mascarada (V5)'])
        ->and($names('primera edición'))->toBe(['Pathfinder 1e'])   // en el lema
        ->and($names('', 'FATE'))->toBe(['FATE Básico'])
        ->and($names('100%'))->toBe([])                              // los comodines de LIKE no cuentan
        ->and($this->catalog->gameLines())->toHaveKey('D&D 5e');
});

it('no lista lo privado, lo oculto ni lo que no está publicado', function () {
    $editor = new TemplateEditor;
    $draft = $editor->create($this->user, 'Borrador secreto');
    $draft->update(['visibility' => 'public']);

    $unlisted = $editor->create($this->user, 'Oculta por enlace');
    $editor->addField($unlisted, $unlisted->tabs()->first()->sections()->first()->id, 'number');
    (new PublishTemplate)($unlisted);
    $unlisted->update(['visibility' => 'unlisted']);

    $names = $this->catalog->search('')->pluck('name');
    expect($names)->not->toContain('Borrador secreto')->not->toContain('Oculta por enlace');

    // La oculta sí se abre con su enlace.
    Livewire::actingAs(User::factory()->create())->test(Show::class, ['slug' => $unlisted->slug])->assertSee('Oculta por enlace');
});

it('me gusta y quita el me gusta, con su contador', function () {
    $dnd = Template::where('slug', 'dnd-5e')->sole();

    expect($this->catalog->toggleLike($this->user, $dnd))->toBeTrue()
        ->and($dnd->fresh()->likes_count)->toBe(1)
        ->and($this->catalog->likes($this->user, $dnd))->toBeTrue()
        ->and($this->catalog->toggleLike($this->user, $dnd))->toBeFalse()
        ->and($dnd->fresh()->likes_count)->toBe(0);
});

it('cuenta una instalación por persona, y no la del autor', function () {
    $dnd = Template::where('slug', 'dnd-5e')->sole();
    $author = $this->author;

    app(CreateSheet::class)($dnd, $this->user);
    app(CreateSheet::class)($dnd, $this->user);
    app(CreateSheet::class)($dnd, $author);

    expect($dnd->fresh()->installs_count)->toBe(1);
});

it('clona la versión publicada, no el borrador, y recuerda de dónde viene', function () {
    $blades = Template::where('slug', 'blades-in-the-dark')->sole();

    // El autor tiene cambios sin publicar: no deben salir en el clon.
    (new TemplateEditor)->addField($blades, $blades->tabs()->first()->sections()->first()->id, 'text', label: 'Campo a medias');

    [$copy, $warnings] = $this->catalog->fork($this->user, $blades);

    expect($warnings)->toBe([])
        ->and($copy->owner_id)->toBe($this->user->id)
        ->and($copy->forked_from_id)->toBe($blades->id)
        ->and($copy->name)->toBe('Blades in the Dark (copia)')
        ->and($copy->visibility)->toBe('private')
        ->and($copy->is_official)->toBeFalse()
        ->and($copy->isPublished())->toBeFalse()
        ->and($copy->fields()->pluck('label'))->not->toContain('Campo a medias')
        ->and($copy->fields()->count())->toBe(count($blades->currentVersion->schema()->fields))
        ->and($copy->theme)->toEqual(['preset' => 'maquina', 'mode' => 'dark']);

    // Y se puede publicar tal cual.
    (new PublishTemplate)($copy);
    expect($copy->fresh()->isPublished())->toBeTrue();
});

it('no clona ni reporta lo que no se puede ver', function () {
    $private = (new TemplateEditor)->create(User::factory()->create(), 'Privada');

    expect(fn () => $this->catalog->fork($this->user, $private))->toThrow(CatalogException::class)
        ->and(fn () => $this->catalog->report($this->user, $private, 'spam', null))->toThrow(CatalogException::class);
});

it('reportar: uno por persona, con motivo', function () {
    $dnd = Template::where('slug', 'dnd-5e')->sole();

    $this->catalog->report($this->user, $dnd, 'copyright', 'Copia la tabla de conjuros del manual');
    $this->catalog->report($this->user, $dnd, 'spam', null);

    expect(DB::table('template_reports')->where('template_id', $dnd->id)->count())->toBe(1)
        ->and(DB::table('template_reports')->value('reason'))->toBe('spam')
        ->and(fn () => $this->catalog->report($this->user, $dnd, 'inventado', null))->toThrow(CatalogException::class, 'motivo');
});

// ---------------------------------------------------------------- pantallas

it('el catálogo busca desde la URL y enseña las tarjetas', function () {
    // Se mira el enlace de cada tarjeta: el nombre de la línea también sale en el filtro.
    $card = fn (string $slug) => 'href="'.route('catalog.show', $slug).'"';

    Livewire::actingAs($this->user)->withQueryParams(['q' => 'blades'])->test(Index::class)
        ->assertSeeHtml($card('blades-in-the-dark'))
        ->assertDontSeeHtml($card('pathfinder-1e'))
        ->set('query', '')
        ->set('line', 'Pathfinder 1e')
        ->assertSeeHtml($card('pathfinder-1e'))
        ->assertDontSeeHtml($card('blades-in-the-dark'));
});

it('la ficha enseña la vista previa y deja usar, clonar y dar me gusta', function () {
    $component = Livewire::actingAs($this->user)->test(Show::class, ['slug' => 'pathfinder-1e'])
        ->assertSee('Así es una hoja nueva')
        ->assertSee('Ataque base')
        ->assertSee('Clonar')
        ->call('toggleLike')
        ->assertSee('♥ Te gusta');

    $component->call('fork')->assertRedirect(route('templates.builder', Template::where('forked_from_id', Template::where('slug', 'pathfinder-1e')->value('id'))->sole()));

    Livewire::actingAs($this->user)->test(Show::class, ['slug' => 'pathfinder-1e'])
        ->call('useTemplate')
        ->assertRedirect();

    expect(Template::where('slug', 'pathfinder-1e')->value('installs_count'))->toBe(1);
});

it('una ficha privada o inexistente es un 404', function () {
    $private = (new TemplateEditor)->create(User::factory()->create(), 'Privada');

    $this->actingAs($this->user)->get(route('catalog.show', $private->slug))->assertNotFound();
    $this->actingAs($this->user)->get(route('catalog.show', 'no-existe'))->assertNotFound();
});

it('avisa de los derechos al hacer pública una plantilla', function () {
    $template = (new TemplateEditor)->create($this->user, 'Mía');

    Livewire::actingAs($this->user)->test(Builder::class, ['template' => $template])
        ->assertDontSeeHtml('data-copyright-notice')
        ->set('form.visibility', 'public')
        ->assertSeeHtml('data-copyright-notice');
});
