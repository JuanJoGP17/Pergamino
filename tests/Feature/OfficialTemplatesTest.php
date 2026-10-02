<?php

use App\Domain\Schema\SchemaValidator;
use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\SaveSheet;
use App\Domain\Theme\SheetTheme;
use App\Livewire\Sheet\Editor;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\BladesTemplateSeeder;
use Database\Seeders\Dnd5eTemplateSeeder;
use Database\Seeders\FateTemplateSeeder;
use Database\Seeders\VampiroTemplateSeeder;
use Livewire\Livewire;

/**
 * Entregable de la Fase 4: el catálogo de campos cubre D&D 5e, FATE,
 * Vampiro y Blades. Cada sistema es una plantilla publicada, sin una línea
 * de código propia, y su hoja se pinta y calcula.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
});

dataset('sistemas', [
    'D&D 5e' => [Dnd5eTemplateSeeder::class, 'dnd-5e'],
    'FATE' => [FateTemplateSeeder::class, 'fate-basico'],
    'Vampiro' => [VampiroTemplateSeeder::class, 'vampiro-v5'],
    'Blades' => [BladesTemplateSeeder::class, 'blades-in-the-dark'],
]);

it('publica la plantilla sin errores ni avisos y pinta todas sus pestañas', function (string $seeder, string $slug) {
    (new $seeder)->run();
    $template = Template::where('slug', $slug)->sole();

    expect($template->isPublished())->toBeTrue()
        ->and((new SchemaValidator)->validate($template))->toBe([]);

    $sheet = app(CreateSheet::class)($template, $this->user);
    $editor = Livewire::actingAs($this->user)->test(Editor::class, ['sheet' => $sheet]);

    foreach ($sheet->schema()->visibleTabs() as $tab) {
        expect($editor->call('selectTab', $tab['key'])->html())
            ->not->toContain('todavía no está disponible')
            ->not->toMatch('/<(input|select|textarea|section|div|span|button|label|path|td)\b[^>]*<!--/');
    }

    expect($sheet->computed['_errors'] ?? [])->toBe([]);
})->with('sistemas');

it('FATE: las casillas de estrés crecen con Físico', function () {
    (new FateTemplateSeeder)->run();
    $sheet = app(CreateSheet::class)(Template::where('slug', 'fate-basico')->sole(), $this->user);

    expect($sheet->computed['estres_fisico']['boxes'])->toBe(2);

    $data = $sheet->data;
    $data['habilidades']['fisico']['level'] = 'grande';
    app(SaveSheet::class)($sheet, $data, $this->user);

    expect($sheet->fresh()->computed['estres_fisico']['boxes'])->toBe(4)
        ->and($sheet->fresh()->computed['habilidades']['fisico']['bonus'])->toBe(3);
});

it('Vampiro: Salud = Resistencia + 3 y Voluntad = Compostura + Resolución', function () {
    (new VampiroTemplateSeeder)->run();
    $sheet = app(CreateSheet::class)(Template::where('slug', 'vampiro-v5')->sole(), $this->user);

    expect($sheet->data['resistencia'])->toBe([1, 0, 0, 0, 0])
        ->and($sheet->computed['salud']['boxes'])->toBe(4)
        ->and($sheet->computed['voluntad']['boxes'])->toBe(2);

    app(SaveSheet::class)($sheet, ['resistencia' => [1, 1, 1, 0, 0], 'salud' => [2, 2, 1, 1, 1, 1], 'fuerza' => [1, 1, 0, 0, 0]], $this->user);
    $sheet = $sheet->fresh();

    expect($sheet->computed['salud'])->toBe(['boxes' => 6, 'marked' => 6])
        ->and($sheet->computed['deteriorado'])->toStartWith('Deteriorado');
});

it('Blades: el valor de un atributo es cuántas de sus acciones tienen puntos', function () {
    (new BladesTemplateSeeder)->run();
    $sheet = app(CreateSheet::class)(Template::where('slug', 'blades-in-the-dark')->sole(), $this->user);

    $data = $sheet->data;
    $data['perspicacia']['estudiar']['level'] = 'p2';
    $data['perspicacia']['cazar']['level'] = 'p1';
    $data['objetos'] = [['objeto' => 'Pistola', 'carga' => 1, 'llevado' => true], ['objeto' => 'Ganzúas', 'carga' => 1, 'llevado' => false]];
    app(SaveSheet::class)($sheet, $data, $this->user);
    $sheet = $sheet->fresh();

    expect($sheet->computed['valor_perspicacia'])->toBe(2)
        ->and($sheet->computed['carga_usada'])->toBe('1 / 5');

    Livewire::actingAs($this->user)->test(Editor::class, ['sheet' => $sheet])->assertSee('2d6');
});

it('cada sistema se ve distinto: su propio tema', function () {
    foreach ([Dnd5eTemplateSeeder::class, FateTemplateSeeder::class, VampiroTemplateSeeder::class, BladesTemplateSeeder::class] as $seeder) {
        (new $seeder)->run();
    }

    $themes = Template::whereIn('slug', ['dnd-5e', 'fate-basico', 'vampiro-v5', 'blades-in-the-dark'])
        ->get()
        ->mapWithKeys(fn (Template $t) => [$t->slug => SheetTheme::forTemplate($t)]);

    expect($themes->map(fn ($t) => $t['preset'])->sort()->values()->all())->toBe(['grimorio', 'maquina', 'minimal', 'pergamino'])
        ->and($themes['blades-in-the-dark']['mode'])->toBe('dark')
        ->and($themes['vampiro-v5']['colors_dark']['accent'])->toBe('#d3203f')
        ->and($themes->map(fn ($t) => $t['typography']['heading'])->unique())->toHaveCount(4);
});
