<?php

use App\Domain\Builder\FieldConfig;
use App\Domain\Schema\FieldType;
use App\Domain\Sheet\CreateSheet;
use App\Livewire\Sheet\Editor;
use App\Livewire\Template\Preview;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Los tipos de rol (Fase 4) dentro del editor de hojas: se pintan, guardan
 * saneados y sus acciones (subir retrato, descansar) funcionan.
 */
beforeEach(function () {
    $this->user = User::factory()->create();

    [$this->template] = publishWith([
        ['key' => 'nivel', 'label' => 'Nivel', 'type' => 'number', 'default_value' => ['value' => 3]],
        ['key' => 'pv', 'label' => 'Puntos de golpe', 'type' => 'resource', 'config' => cfg('resource', ['max_formula' => '@nivel * 10', 'reset_on' => 'long'])],
        ['key' => 'salud', 'label' => 'Salud', 'type' => 'track', 'config' => cfg('track', ['boxes' => 4, 'states' => "Superficial\nAgravado", 'shape' => 'dot'])],
        ['key' => 'reloj', 'label' => 'Reloj', 'type' => 'clock', 'config' => cfg('clock', ['segments' => 6])],
        ['key' => 'usos', 'label' => 'Usos de Ki', 'type' => 'counter', 'config' => cfg('counter', ['min' => 0, 'max' => 5, 'reset_on' => 'short'])],
        ['key' => 'xp', 'label' => 'Experiencia', 'type' => 'progress', 'config' => cfg('progress', ['thresholds' => '0, 300, 900'])],
        ['key' => 'bolsa', 'label' => 'Bolsa', 'type' => 'currency', 'config' => cfg('currency', FieldConfig::defaults(FieldType::Currency))],
        ['key' => 'sigilo', 'label' => 'Sigilo', 'type' => 'proficiency', 'config' => cfg('proficiency', ['levels' => "no | — | 0\ncomp | Competente | 2"])],
        ['key' => 'habilidades', 'label' => 'Habilidades', 'type' => 'derived_list', 'config' => cfg('derived_list', ['items' => "atletismo | Atletismo\nhistoria | Historia", 'levels' => "no | — | 0\ncomp | Competente | 2"])],
        ['key' => 'inv', 'label' => 'Inventario', 'type' => 'repeater', 'config' => cfg('repeater', ['columns' => "nombre | Objeto | texto\npeso | Peso | número\ntipo | Tipo | lista: Arma, Otro\nlleva | Lo lleva | casilla\ndoble | Doble | = @row.peso * 2"])],
        ['key' => 'idiomas', 'label' => 'Idiomas', 'type' => 'multiselect', 'config' => cfg('multiselect', ['options' => "Común\nÉlfico"])],
        ['key' => 'rasgos', 'label' => 'Rasgos', 'type' => 'tags', 'config' => cfg('tags', ['suggestions' => "Valiente\nCurioso"])],
        ['key' => 'color', 'label' => 'Color favorito', 'type' => 'color'],
        ['key' => 'retrato', 'label' => 'Retrato', 'type' => 'portrait', 'config' => cfg('portrait', ['shape' => 'circle'])],
        ['key' => 'mapa', 'label' => 'Mapa', 'type' => 'image', 'config' => cfg('image', ['aspect' => 'landscape'])],
        ['key' => 'ataque', 'label' => 'Atacar', 'type' => 'dice_button', 'roll_expression' => '1d20 + {@nivel}'],
    ]);
    $this->template->update(['owner_id' => $this->user->id]);
    $this->sheet = app(CreateSheet::class)($this->template, $this->user);
});

function editor($test)
{
    return Livewire::actingAs($test->user)->test(Editor::class, ['sheet' => $test->sheet]);
}

it('pinta todos los tipos de rol sin marcadores de Livewire dentro de las etiquetas', function () {
    $html = editor($this)->html();

    expect($html)
        ->not->toMatch('/<(input|select|textarea|section|div|span|button|label|path|svg|td|tr|table|fieldset)\b[^>]*<!--/')
        ->not->toContain('todavía no está disponible')
        ->toContain('wire:model.live.blur="data.pv.current"')
        ->toContain('data-formula-path="current"')
        ->toContain('data-formula-path="atletismo.level"')
        ->toContain('wire:model.live.blur="data.bolsa.po"')
        ->toContain('Superficial')
        ->toContain('+ Añadir fila')
        ->toContain('aria-label="Segmento 6"')
        ->toContain('Élfico')
        ->toContain('Valiente')
        ->toContain('Subir imagen')
        ->toContain('1d20 + 3');
});

it('guarda los valores compuestos saneados y con sus derivadas', function () {
    editor($this)
        ->set('data.pv.current', '999')
        ->set('data.salud', [1, 2, 7])
        ->set('data.usos', 9)
        ->set('data.inv', [['nombre' => 'Espada', 'peso' => '3', 'tipo' => 'Arma', 'lleva' => true, 'doble' => 'hack']])
        ->set('data.idiomas', ['Élfico', 'Klingon'])
        ->assertSet('data.pv.current', 30)
        ->assertSet('data.salud', [1, 2, 2, 0])
        ->assertSet('data.usos', 5);

    $sheet = $this->sheet->fresh();

    expect($sheet->data['inv'])->toEqual([['nombre' => 'Espada', 'peso' => 3, 'tipo' => 'Arma', 'lleva' => true]])
        ->and($sheet->computed['inv'])->toEqual([['doble' => 6]])
        ->and($sheet->data['idiomas'])->toBe(['Élfico'])
        ->and($sheet->computed['pv'])->toEqual(['max' => 30, 'pct' => 100])
        ->and($sheet->computed['salud'])->toEqual(['boxes' => 4, 'marked' => 3]);
});

it('un descanso corto recupera lo suyo y el largo, todo', function () {
    $e = editor($this)
        ->set('data.pv.current', 4)
        ->set('data.usos', 1)
        ->assertSee('Descanso corto')
        ->assertSee('Descanso largo');

    $e->call('rest', 'short')->assertSet('data.usos', 5)->assertSet('data.pv.current', 4);
    $e->set('data.usos', 0)->call('rest', 'long')->assertSet('data.usos', 5)->assertSet('data.pv.current', 30);
});

it('sube un retrato: lo reescribe en WebP, lo reduce y lo sirve con URL firmada', function () {
    Storage::fake('local');

    $e = editor($this)->set('uploads.retrato', UploadedFile::fake()->image('yo.jpg', 2400, 1200));

    $media = Media::sole();
    expect($e->get('data.retrato'))->toBe($media->id)
        ->and($media->mime)->toBe('image/webp')
        ->and([$media->width, $media->height])->toBe([1600, 800])
        ->and($media->user_id)->toBe($this->user->id)
        ->and($media->path)->toStartWith("media/{$this->user->id}/")
        ->and(Storage::disk('local')->exists($media->path))->toBeTrue()
        ->and(substr(Storage::disk('local')->get($media->path), 8, 4))->toBe('WEBP');

    preg_match('#src="([^"]*/media/'.$media->id.'\?[^"]*)"#', $e->html(), $m);
    $url = html_entity_decode($m[1] ?? '');

    expect($url)->toContain('signature=');
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/webp');
    $this->get(route('media.show', $media))->assertForbidden();

    $e->call('clearImage', 'retrato')->assertSet('data.retrato', null);
});

it('rechaza un archivo que no es una imagen aunque lo diga su nombre', function () {
    Storage::fake('local');

    editor($this)
        ->set('uploads.retrato', UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo "hola";'))
        ->assertHasErrors('uploads.retrato')
        ->assertSet('data.retrato', null);

    expect(Media::count())->toBe(0);
});

it('la vista previa del constructor pinta los tipos de rol sin dejar subir imágenes', function () {
    Livewire::actingAs($this->user)
        ->test(Preview::class, ['templateUuid' => $this->template->uuid])
        ->assertSee('Puntos de golpe')
        ->assertSee('Las imágenes se suben desde la hoja')
        ->assertDontSee('Subir imagen')
        ->set('data.salud', [1, 0, 0, 0])
        ->assertSet('data.salud', [1, 0, 0, 0]);
});
