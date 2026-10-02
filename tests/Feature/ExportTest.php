<?php

use App\Domain\Media\StoreImage;
use App\Domain\Schema\PublishTemplate;
use App\Domain\Schema\SchemaValidator;
use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\SaveSheet;
use App\Domain\Sheet\SheetPrinter;
use App\Domain\Theme\SheetTheme;
use App\Domain\Transfer\SheetTransfer;
use App\Domain\Transfer\TemplateTransfer;
use App\Domain\Transfer\TransferException;
use App\Livewire\Dashboard;
use App\Livewire\Template\Index;
use App\Models\Media;
use App\Models\Sheet;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\Dnd5eTemplateSeeder;
use Database\Seeders\VampiroTemplateSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Impresión, PDF y exportación/importación JSON (§6.4, Fase 5).
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

function vampireSheet(User $user): Sheet
{
    (new VampiroTemplateSeeder)->run();
    $sheet = app(CreateSheet::class)(Template::where('slug', 'vampiro-v5')->sole(), $user);
    app(SaveSheet::class)($sheet, [
        'nombre_personaje' => 'Lucía Varga',
        'clan' => 'Toreador',
        'salud' => [2, 1, 0, 0],
        'disciplinas' => [['disciplina' => 'Auspex', 'nivel' => 2, 'poderes' => 'Sentidos agudos']],
    ], $user);

    return $sheet->fresh();
}

// --------------------------------------------------------------- impresión

it('imprime todas las pestañas con los valores ya formateados', function () {
    $sheet = vampireSheet($this->user);

    $this->actingAs($this->user)->get(route('sheets.print', $sheet))
        ->assertOk()
        ->assertSee('Lucía Varga')
        ->assertSee('Toreador')
        ->assertSee('Salud y sangre')                  // la segunda pestaña también
        ->assertSee('✕ ╱ □ □')                         // agravado, superficial y dos vacías
        ->assertSee('Sentidos agudos')
        ->assertSee('Imprimir');
});

it('genera el PDF con dompdf, con marca de agua si se pide', function () {
    $sheet = vampireSheet($this->user);

    $pdf = $this->actingAs($this->user)->get(route('sheets.pdf', $sheet).'?marca=1');

    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-')
        ->and(strlen($pdf->getContent()))->toBeGreaterThan(5000);
});

it('incrusta el retrato en el PDF', function () {
    Storage::fake('local');
    $sheet = vampireSheet($this->user);
    $media = app(StoreImage::class)(UploadedFile::fake()->image('r.png', 300, 300), $this->user);
    app(SaveSheet::class)($sheet, ['retrato' => $media->id], $this->user);

    $html = view('print.sheet', [
        'sheet' => $sheet->fresh(), 'tabs' => (new SheetPrinter($sheet->fresh()))->tabs(embedImages: true),
        'theme' => SheetTheme::forSheet($sheet), 'pdf' => true, 'watermark' => null,
    ])->render();

    expect($html)->toContain('src="data:image/png;base64,');
});

it('no deja imprimir ni exportar la hoja de otro', function () {
    $sheet = vampireSheet($this->user);
    $other = User::factory()->create();

    $this->actingAs($other)->get(route('sheets.print', $sheet))->assertForbidden();
    $this->actingAs($other)->get(route('sheets.pdf', $sheet))->assertForbidden();
    $this->actingAs($other)->get(route('sheets.export', $sheet))->assertForbidden();
});

// ------------------------------------------------------- plantillas en JSON

it('exporta una plantilla y al importarla queda igual, como borrador nuevo', function () {
    (new Dnd5eTemplateSeeder)->run();
    $original = Template::where('slug', 'dnd-5e')->sole();
    $original->update(['theme' => ['preset' => 'grimorio', 'custom_background' => ['media_id' => 1, 'opacity' => 0.3]]]);

    $json = $this->actingAs($this->user)->get(route('templates.export', $original))->assertOk()->streamedContent();
    $data = json_decode($json, true);

    expect($data['format'])->toBe('pergamino.template')
        ->and($data['template']['theme'])->toEqual(['preset' => 'grimorio', 'custom_background' => ['opacity' => 0.3]]);

    [$copy, $warnings] = app(TemplateTransfer::class)->import($this->user, $data);

    expect($warnings)->toBe([])
        ->and($copy->owner_id)->toBe($this->user->id)
        ->and($copy->visibility)->toBe('private')
        ->and($copy->isPublished())->toBeFalse()
        ->and($copy->slug)->not->toBe('dnd-5e')
        ->and($copy->fields()->count())->toBe($original->fields()->count())
        ->and((new SchemaValidator)->validate($copy))->toBe([]);

    // Y se puede publicar y calcula lo mismo.
    (new PublishTemplate)($copy);
    $sheet = app(CreateSheet::class)($copy->fresh(), $this->user);
    expect($sheet->computed['ca'])->toBe(10)
        ->and(app(TemplateTransfer::class)->export($copy->fresh())['tabs'])->toEqual($data['tabs']);
});

it('importar una plantilla sanea lo que trae el archivo', function () {
    [$template, $warnings] = app(TemplateTransfer::class)->import($this->user, [
        'format' => 'pergamino.template', 'format_version' => 1,
        'template' => ['name' => 'Rara', 'theme' => ['colors' => ['accent' => 'red;}']], 'settings' => ['lookups' => ['t' => ['1' => 2, 'x' => ['no']]]]],
        'tabs' => [['key' => 'a', 'label' => 'A', 'sections' => [['key' => 's', 'label' => 'S', 'fields' => [
            ['key' => 'fuerza', 'type' => 'number', 'label' => 'Fuerza'],
            ['key' => 'fuerza', 'type' => 'number', 'label' => 'Otra fuerza'],
            ['key' => 'self', 'type' => 'text', 'label' => 'Reservada'],
            ['key' => 'x', 'type' => 'inventado', 'label' => 'Rara'],
            ['key' => 'pv', 'type' => 'resource', 'label' => 'PV', 'config' => ['bar_color' => 'url(x)', 'max_formula' => '@fuerza * 2']],
        ]]]]],
    ]);

    expect($template->fields()->orderBy('position')->pluck('key')->all())->toBe(['fuerza', 'fuerza_2', 'self_2', 'pv'])
        ->and($template->fields()->where('key', 'pv')->value('config'))->toEqual(['show_temp' => true, 'allow_overflow' => false, 'max_formula' => '@fuerza * 2'])
        ->and($template->theme)->toBeNull()
        ->and($template->settings['lookups'])->toBe(['t' => [1 => 2]])
        ->and($warnings)->toHaveCount(3);
});

it('rechaza archivos que no son plantillas', function () {
    expect(fn () => app(TemplateTransfer::class)->import($this->user, ['format' => 'otra cosa']))
        ->toThrow(TransferException::class, 'no es una plantilla exportada')
        ->and(fn () => app(TemplateTransfer::class)->import($this->user, null))->toThrow(TransferException::class);
});

it('importa una plantilla desde el listado y abre el constructor', function () {
    $json = json_encode(['format' => 'pergamino.template', 'format_version' => 1, 'template' => ['name' => 'Desde archivo'],
        'tabs' => [['label' => 'General', 'sections' => [['label' => 'Principal', 'fields' => [['key' => 'nivel', 'type' => 'number', 'label' => 'Nivel']]]]]]]);

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('p.json', $json))
        ->assertRedirect(route('templates.builder', Template::where('name', 'Desde archivo')->sole()));

    Livewire::actingAs($this->user)->test(Index::class)
        ->set('importFile', UploadedFile::fake()->createWithContent('p.json', 'esto no es json'))
        ->assertHasErrors('importFile');
});

// ------------------------------------------------------------ hojas en JSON

it('exporta una hoja y la importa como hoja nueva, sin imágenes y recalculada', function () {
    $sheet = vampireSheet($this->user);
    $sheet->update(['theme_override' => ['colors' => ['accent' => '#112233']]]);
    $media = Media::create(['user_id' => $this->user->id, 'path' => 'a.webp', 'mime' => 'image/webp', 'size' => 1]);
    app(SaveSheet::class)($sheet, ['retrato' => $media->id], $this->user);

    $data = json_decode($this->actingAs($this->user)->get(route('sheets.export', $sheet))->assertOk()->streamedContent(), true);

    expect($data['format'])->toBe('pergamino.sheet')
        ->and($data['sheet']['data'])->not->toHaveKey('retrato')
        ->and($data)->not->toHaveKey('computed');

    $data['sheet']['data']['inventado'] = 1;

    $transfer = app(SheetTransfer::class);
    $template = $transfer->originalTemplate($this->user, $data);
    [$copy, $warnings] = $transfer->import($this->user, $data, $template);

    expect($copy->id)->not->toBe($sheet->id)
        ->and($copy->name)->toBe($sheet->name)
        ->and($copy->data['clan'])->toBe('Toreador')
        ->and($copy->data['salud'])->toBe($sheet->data['salud'])
        ->and($copy->computed['salud'])->toBe($sheet->computed['salud'])
        ->and($copy->theme_override)->toBe(['colors' => ['accent' => '#112233']])
        ->and($warnings[0])->toContain('inventado');
});

it('importa una hoja desde el panel', function () {
    $sheet = vampireSheet($this->user);
    $json = json_encode(app(SheetTransfer::class)->export($sheet));

    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->set('sheetFile', UploadedFile::fake()->createWithContent('h.json', $json))
        ->assertRedirect();

    expect(Sheet::where('owner_id', $this->user->id)->count())->toBe(2);

    // Sin plantilla reconocible, pide elegirla.
    $other = json_encode(['format' => 'pergamino.sheet', 'format_version' => 1, 'template' => ['slug' => 'no-existe'], 'sheet' => ['data' => []]]);
    Livewire::actingAs($this->user)->test(Dashboard::class)
        ->set('sheetFile', UploadedFile::fake()->createWithContent('h.json', $other))
        ->assertHasErrors('sheetFile');
});
