<?php

use App\Domain\Sheet\CreateSheet;
use App\Domain\Theme\Presets;
use App\Domain\Theme\SheetTheme;
use App\Domain\Theme\Theme;
use App\Domain\Theme\ThemeCompiler;
use App\Livewire\Sheet\Editor;
use App\Livewire\Template\Appearance;
use App\Livewire\Template\Preview;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Temas (§6.3, Fase 5): lista blanca, cascada plantilla → mesa → hoja,
 * compilación a variables CSS y los editores.
 */

// ------------------------------------------------------------ lista blanca

it('descarta todo lo que no está en la lista blanca, también los intentos de inyectar CSS', function () {
    $theme = Theme::sanitize([
        'preset' => 'inventado',
        'mode' => 'dark',
        'colors' => ['accent' => 'red;}</style><script>alert(1)</script>', 'bg' => '#ABCDEF', 'ink' => '#fff'],
        'typography' => ['heading' => 'Comic Sans', 'body' => 'inter', 'scale' => 9, 'heading_transform' => 'rotate'],
        'surface' => ['texture' => 'url(javascript:x)', 'corner_radius' => -5, 'border_style' => 'ornate', 'shadow' => 'soft'],
        'layout' => ['density' => 'compact', 'max_width' => 99999],
        'custom_background' => ['media_id' => 'x', 'opacity' => '0.5', 'repeat' => 'tile'],
        'css' => 'body { display: none }',
    ]);

    expect($theme)->toBe([
        'mode' => 'dark',
        'colors' => ['bg' => '#abcdef'],
        'typography' => ['body' => 'inter', 'scale' => 1.3],
        'surface' => ['corner_radius' => 0, 'border_style' => 'ornate', 'shadow' => 'soft'],
        'layout' => ['density' => 'compact', 'max_width' => 1600],
        'custom_background' => ['opacity' => 0.5, 'repeat' => 'tile'],
    ]);
});

it('una hoja solo puede cambiar el acento y el modo', function () {
    expect(Theme::sanitize([
        'mode' => 'light',
        'preset' => 'cyberpunk',
        'colors' => ['accent' => '#112233', 'bg' => '#000000'],
        'typography' => ['heading' => 'inter'],
    ], sheetLayer: true))->toBe(['mode' => 'light', 'colors' => ['accent' => '#112233']]);
});

// ---------------------------------------------------------------- cascada

it('resuelve la cascada preset → plantilla → mesa → hoja', function () {
    $theme = Theme::resolve(
        Theme::sanitize(['preset' => 'grimorio', 'colors_dark' => ['accent' => '#aa0000'], 'typography' => ['body' => 'inter']]),
        Theme::sanitize(['typography' => ['heading' => 'cinzel'], 'colors_dark' => ['bg' => '#000000']]),
        Theme::sanitize(['colors_dark' => ['accent' => '#00ff00']], sheetLayer: true),
    );

    $grimorio = Presets::get('grimorio');

    expect($theme['preset'])->toBe('grimorio')
        ->and($theme['mode'])->toBe('dark')
        ->and($theme['colors_dark']['accent'])->toBe('#00ff00')            // la hoja manda
        ->and($theme['colors_dark']['bg'])->toBe('#000000')                // la mesa
        ->and($theme['colors_dark']['ink'])->toBe($grimorio['colors_dark']['ink'])   // el preset
        ->and($theme['typography'])->toBe(['heading' => 'cinzel', 'body' => 'inter', 'scale' => 1.05, 'heading_transform' => 'small-caps']);
});

it('los siete presets del plan, completos y válidos', function () {
    expect(array_values(Presets::options()))->toBe([
        'Pergamino', 'Grimorio oscuro', 'Cyberpunk neón', 'Minimal papel',
        'Sci-fi terminal', 'Cómic', 'Máquina de escribir',
    ]);

    foreach (Presets::all() as $key => $preset) {
        $clean = Theme::sanitize(['preset' => $key] + $preset);

        // Todo lo que define el preset pasa su propia lista blanca.
        expect(array_diff_key($clean, ['preset' => 1]))->toEqual(array_diff_key($preset, ['label' => 1]), $key);
    }
});

// ------------------------------------------------------------ compilación

it('compila a variables CSS con ámbito en la hoja, y oscuro según el dispositivo', function () {
    $css = ThemeCompiler::css(Theme::resolve(['preset' => 'pergamino']), 'abc-123', fn () => null);

    expect($css)->toStartWith('[data-sheet="abc-123"]{--pg-bg:#f4ecd8;')
        ->toContain('--pg-font-heading:"Cinzel"')
        ->toContain('--pg-radius:8px')
        ->toContain('@media (prefers-color-scheme: dark){[data-sheet="abc-123"]{--pg-bg:#1b1713;')
        ->not->toContain('--pg-bg-image');

    // Modo forzado: la paleta oscura va directa y no hay @media.
    $dark = ThemeCompiler::css(Theme::resolve(['preset' => 'terminal']), 'x', fn () => null);
    expect($dark)->toStartWith('[data-sheet="x"]{--pg-bg:#040904;')->not->toContain('@media');
});

it('el ámbito y la URL del fondo no pueden romper el CSS', function () {
    $theme = Theme::resolve(['custom_background' => ['media_id' => 5]]);

    $css = ThemeCompiler::css($theme, 'a"]{}body{color:red', fn () => 'http://x/y");}body{display:none');
    expect($css)->toStartWith('[data-sheet="abodycolorred"]')->not->toContain('display:none');

    $ok = ThemeCompiler::css($theme, 'a', fn (int $id) => "http://127.0.0.1/media/{$id}?expires=1&signature=ab12");
    expect($ok)->toContain('--pg-bg-image:url("http://127.0.0.1/media/5?expires=1&signature=ab12")')
        ->toContain('--pg-bg-opacity:0.15');
});

// ------------------------------------------------------- en las hojas

beforeEach(function () {
    $this->user = User::factory()->create();
    [$this->template] = publishWith([['key' => 'fuerza', 'label' => 'Fuerza', 'type' => 'attribute', 'config' => cfg('attribute', [])]]);
    $this->template->update(['owner_id' => $this->user->id]);
});

it('la hoja se pinta con el tema de la plantilla, en vivo y sin publicar', function () {
    $sheet = app(CreateSheet::class)($this->template, $this->user);

    $this->template->update(['theme' => ['preset' => 'cyberpunk']]);

    Livewire::actingAs($this->user)->test(Editor::class, ['sheet' => $sheet])
        ->assertSeeHtml('<style>[data-sheet="'.$sheet->uuid.'"]{--pg-bg:#070a14;')
        ->assertSeeHtml('class="pg-sheet space-y-6"');
});

it('la mesa y la hoja se suman a la cascada', function () {
    $sheet = app(CreateSheet::class)($this->template, $this->user);
    $campaign = Campaign::create(['gm_id' => $this->user->id, 'name' => 'Mesa', 'join_code' => 'ABC123',
        'theme_override' => ['typography' => ['heading' => 'special_elite']]]);
    $sheet->update(['theme_override' => ['colors' => ['accent' => '#123456'], 'typography' => ['heading' => 'inter']]]);

    $theme = SheetTheme::forSheet($sheet->fresh(), $campaign);

    expect($theme['typography']['heading'])->toBe('special_elite')   // la hoja no puede tocar la tipografía
        ->and($theme['colors']['accent'])->toBe('#123456');
});

it('el editor de apariencia guarda, aplica presets y repinta la vista previa', function () {
    $component = Livewire::actingAs($this->user)
        ->test(Appearance::class, ['template' => $this->template])
        ->assertSee('Máquina de escribir')
        ->call('choosePreset', 'maquina')
        ->assertSet('form.typography.heading', 'special_elite')
        ->assertDispatched('theme-changed')
        ->set('form.colors.accent', '#00AA00')
        ->set('form.colors.ink', 'no-es-un-color')
        ->assertSet('form.colors.ink', Presets::get('maquina')['colors']['ink']);   // lo inválido vuelve a lo válido

    expect($this->template->fresh()->theme)->toMatchArray(['preset' => 'maquina'])
        ->and($this->template->fresh()->theme['colors']['accent'])->toBe('#00aa00');

    Livewire::actingAs($this->user)->test(Preview::class, ['templateUuid' => $this->template->uuid])
        ->assertSeeHtml('--pg-accent:#00aa00')
        ->assertSeeHtml('"Special Elite"');

    $component->call('resetTheme')->assertSet('form.preset', 'pergamino');
    expect($this->template->fresh()->theme)->toBeNull();
});

it('solo el dueño edita la apariencia y el fondo tiene que ser suyo', function () {
    $other = User::factory()->create();
    Livewire::actingAs($other)->test(Appearance::class, ['template' => $this->template])->assertForbidden();

    $theirs = Media::create(['user_id' => $other->id, 'path' => 'x.webp', 'mime' => 'image/webp', 'size' => 1]);
    Livewire::actingAs($this->user)->test(Appearance::class, ['template' => $this->template])
        ->set('form.custom_background.media_id', $theirs->id);

    expect($this->template->fresh()->theme['custom_background']['media_id'] ?? null)->toBeNull();
});

it('sube una imagen de fondo para el tema', function () {
    Storage::fake('local');

    Livewire::actingAs($this->user)->test(Appearance::class, ['template' => $this->template])
        ->set('background', UploadedFile::fake()->image('fondo.png', 400, 400))
        ->assertHasNoErrors();

    $media = Media::sole();
    expect($media->purpose)->toBe('background')
        ->and($this->template->fresh()->theme['custom_background']['media_id'])->toBe($media->id);

    $sheet = app(CreateSheet::class)($this->template->fresh(), $this->user);
    Livewire::actingAs($this->user)->test(Editor::class, ['sheet' => $sheet])
        ->assertSeeHtml('--pg-bg-image:url("http://');
});

it('cada hoja puede cambiar su acento y su modo, y volver al de la plantilla', function () {
    $sheet = app(CreateSheet::class)($this->template, $this->user);

    $e = Livewire::actingAs($this->user)->test(Editor::class, ['sheet' => $sheet])
        ->set('sheetAccent', '#ABCDEF')
        ->set('sheetMode', 'dark')
        ->assertSeeHtml('--pg-accent:#abcdef');

    expect($sheet->fresh()->theme_override)->toEqual(['mode' => 'dark', 'colors' => ['accent' => '#abcdef'], 'colors_dark' => ['accent' => '#abcdef']]);

    $e->call('clearSheetTheme');
    expect($sheet->fresh()->theme_override)->toBeNull();
});
