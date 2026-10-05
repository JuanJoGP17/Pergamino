<?php

use App\Domain\Campaign\CampaignException;
use App\Domain\Campaign\Campaigns;
use App\Domain\Campaign\Initiative;
use App\Domain\Dice\DiceException;
use App\Domain\Dice\RollDice;
use App\Domain\Sheet\CreateSheet;
use App\Domain\Sheet\SaveSheet;
use App\Domain\Sheet\SheetPrinter;
use App\Models\DiceRoll;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\Dnd5eTemplateSeeder;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Mesas (§8) y tiradas (§7) en el dominio, sin interfaz.
 */
beforeEach(function () {
    $this->campaigns = new Campaigns;
    $this->gm = User::factory()->create();
    $this->player = User::factory()->create();
    $this->spectator = User::factory()->create();

    (new Dnd5eTemplateSeeder)->run();
    $this->template = Template::where('slug', 'dnd-5e')->sole();

    $this->campaign = $this->campaigns->create($this->gm, 'La cripta', 'Una partida de prueba', $this->template->id);
    $this->campaigns->join($this->player, strtolower($this->campaign->join_code));
    $this->campaigns->join($this->spectator, $this->campaign->join_code);
    $this->campaigns->setRole($this->campaign, $this->gm, $this->spectator->id, 'spectator');

    $this->sheet = app(CreateSheet::class)($this->template, $this->player, 'Eldra');
});

// --------------------------------------------------------------- mesas

it('crea la mesa con código y el DJ como miembro, y se une con el código en minúsculas', function () {
    expect($this->campaign->join_code)->toMatch('/^[A-Z]+-\d{4}$/')
        ->and($this->campaign->roleOf($this->gm))->toBe('gm')
        ->and($this->campaign->roleOf($this->player))->toBe('player')
        ->and($this->campaign->roleOf($this->spectator))->toBe('spectator')
        ->and($this->campaign->default_template_id)->toBe($this->template->id)
        ->and($this->gm->campaigns()->count())->toBe(1);

    expect(fn () => $this->campaigns->join($this->player, 'NO-EXISTE'))->toThrow(CampaignException::class, 'ninguna mesa');
});

it('solo el DJ cambia roles y echa; cualquiera puede irse', function () {
    expect(fn () => $this->campaigns->setRole($this->campaign, $this->player, $this->spectator->id, 'player'))
        ->toThrow(CampaignException::class, 'solo puede hacerlo el DJ')
        ->and(fn () => $this->campaigns->setRole($this->campaign, $this->gm, $this->gm->id, 'player'))
        ->toThrow(CampaignException::class, 'El DJ no puede');

    $this->campaigns->addSheet($this->campaign, $this->player, $this->sheet);
    $this->campaigns->removeMember($this->campaign, $this->player, $this->player->id);

    expect($this->campaign->roleOf($this->player))->toBeNull()
        ->and($this->campaign->sheets()->count())->toBe(0);   // su hoja se va con él
});

it('cada uno lleva sus hojas y decide cuánto se ve de ellas', function () {
    expect(fn () => $this->campaigns->addSheet($this->campaign, $this->gm, $this->sheet))
        ->toThrow(CampaignException::class, 'tus propias hojas');

    $this->campaigns->addSheet($this->campaign, $this->player, $this->sheet, 'summary');
    $other = User::factory()->create();
    $this->campaigns->join($other, $this->campaign->join_code);

    $sheet = $this->sheet->fresh();

    // Resumen: los demás jugadores no abren la hoja; el DJ sí, y puede editarla.
    expect($other->can('view', $sheet))->toBeFalse()
        ->and($this->gm->can('view', $sheet))->toBeTrue()
        ->and($this->gm->can('update', $sheet))->toBeTrue();

    $this->campaigns->setShareLevel($this->campaign, $this->player, $sheet, 'full');
    expect($other->can('view', $sheet->fresh()))->toBeTrue()
        ->and($other->can('update', $sheet->fresh()))->toBeFalse();

    $this->campaigns->setShareLevel($this->campaign, $this->gm, $sheet, 'hidden');
    expect($other->can('view', $sheet->fresh()))->toBeFalse();

    // Si el DJ no puede editar hojas en esta mesa, no puede.
    $this->campaigns->updateSettings($this->campaign, $this->gm, ['gm_can_edit_sheets' => false]);
    expect($this->gm->can('update', $sheet->fresh()))->toBeFalse();
});

it('la tarjeta resumen lleva los campos marcados, formateados', function () {
    app(SaveSheet::class)($this->sheet, ['destreza' => 14, 'pv' => ['current' => 9, 'max' => 12, 'temp' => 0]], $this->player);

    $summary = collect((new SheetPrinter($this->sheet->fresh()))->summary())->mapWithKeys(fn ($c) => [$c['key'] => $c['text'] ?? null]);

    expect($summary->all())->toMatchArray([
        'pv' => '9 / 12',
        'ca' => '12',
        'destreza' => '14',
        'iniciativa' => '+2',
        'percepcion_pasiva' => '10',
    ]);
});

it('notas de mesa: cada uno las suyas, y las privadas solo del DJ', function () {
    $note = $this->campaigns->saveNote($this->campaign, $this->player, null, 'Pistas', 'La **llave** está en el pozo', gmOnly: true);
    expect($note->is_gm_only)->toBeFalse();   // un jugador no escribe notas privadas

    $secret = $this->campaigns->saveNote($this->campaign, $this->gm, null, 'Secreto', 'El posadero es un vampiro', gmOnly: true);
    expect($secret->is_gm_only)->toBeTrue();

    expect(fn () => $this->campaigns->saveNote($this->campaign, $this->player, $secret->id, 'x', 'y', false))
        ->toThrow(CampaignException::class, 'tus notas')
        ->and(fn () => $this->campaigns->saveNote($this->campaign, $this->spectator, null, 'x', 'y', false))
        ->toThrow(CampaignException::class, 'espectadores');

    $this->campaigns->deleteNote($this->campaign, $this->gm, $note->id);   // el DJ puede con todas
    expect($this->campaign->notes()->count())->toBe(1);
});

// ------------------------------------------------------------ iniciativa

it('la iniciativa se ordena sola, avanza turnos y rondas, y el turno sigue a su dueño', function () {
    $init = new Initiative;
    $init->add($this->campaign, $this->gm, 'Goblin', 12);
    $init->add($this->campaign->fresh(), $this->gm, 'Eldra', 17);
    $init->add($this->campaign->fresh(), $this->gm, 'Orco', 12);

    $names = fn () => array_column(Initiative::of($this->campaign->fresh())['entries'], 'name');
    expect($names())->toBe(['Eldra', 'Goblin', 'Orco']);

    $init->next($this->campaign->fresh(), $this->gm);                         // turno de Goblin
    $goblin = Initiative::of($this->campaign->fresh())['entries'][1]['id'];
    $init->setValue($this->campaign->fresh(), $this->gm, $goblin, 20);        // pasa al primero…
    $state = Initiative::of($this->campaign->fresh());
    expect($names())->toBe(['Goblin', 'Eldra', 'Orco'])
        ->and($state['entries'][$state['turn']]['name'])->toBe('Goblin');     // …y el turno con él

    $init->next($this->campaign->fresh(), $this->gm);
    $init->next($this->campaign->fresh(), $this->gm);
    $init->next($this->campaign->fresh(), $this->gm);
    expect(Initiative::of($this->campaign->fresh()))->toMatchArray(['round' => 2, 'turn' => 0]);

    $init->previous($this->campaign->fresh(), $this->gm);
    expect(Initiative::of($this->campaign->fresh()))->toMatchArray(['round' => 1, 'turn' => 2]);

    expect(fn () => $init->next($this->campaign->fresh(), $this->player))->toThrow(CampaignException::class, 'la lleva el DJ');

    $init->reset($this->campaign->fresh(), $this->gm);
    expect(Initiative::of($this->campaign->fresh()))->toBe(['round' => 1, 'turn' => 0, 'entries' => []]);
});

it('añade las hojas de la mesa tirando su iniciativa de verdad', function () {
    $this->campaigns->addSheet($this->campaign, $this->player, $this->sheet);

    expect((new Initiative)->addSheets($this->campaign, $this->gm))->toBe(1);

    $entry = Initiative::of($this->campaign->fresh())['entries'][0];
    $roll = DiceRoll::sole();

    expect($entry['name'])->toBe('Eldra')
        ->and($entry['sheet_id'])->toBe($this->sheet->id)
        ->and($entry['value'])->toBe($roll->total())
        ->and($roll->label)->toBe('Eldra · Iniciativa')
        ->and($roll->expression)->toBe('1d20 + 0');
});

// --------------------------------------------------------------- tiradas

it('tira, guarda la tirada y respeta quién puede tirar en la mesa', function () {
    $roll = app(RollDice::class)($this->player, '1d20+5', 'Ataque', campaign: $this->campaign);

    expect($roll->campaign_id)->toBe($this->campaign->id)
        ->and($roll->total())->toBeGreaterThanOrEqual(6)->toBeLessThanOrEqual(25)
        ->and($roll->result['groups'][0]['sides'])->toBe(20);

    expect(fn () => app(RollDice::class)($this->spectator, '1d6', campaign: $this->campaign))
        ->toThrow(DiceException::class, 'No puedes tirar');
});

it('las tiradas secretas solo las ve el DJ (y un jugador no puede hacerlas)', function () {
    app(RollDice::class)($this->gm, '1d20', 'Percepción del dragón', campaign: $this->campaign, private: true);
    $playerTry = app(RollDice::class)($this->player, '1d20', 'Intento', campaign: $this->campaign, private: true);

    expect($playerTry->is_private)->toBeFalse()
        ->and(DiceRoll::visibleTo($this->gm, $this->campaign)->count())->toBe(2)
        ->and(DiceRoll::visibleTo($this->player, $this->campaign)->pluck('label')->all())->toBe(['Intento']);
});

it('como mucho 30 tiradas por minuto', function () {
    RateLimiter::clear('dice:'.$this->player->id);

    for ($i = 0; $i < RollDice::PER_MINUTE; $i++) {
        app(RollDice::class)($this->player, '1d6');
    }

    expect(fn () => app(RollDice::class)($this->player, '1d6'))->toThrow(DiceException::class, 'Demasiadas tiradas');
});

it('una ruta con un uuid que no lo es da 404, también en PostgreSQL', function () {
    $this->actingAs($this->player)->get('/hojas/no-es-un-uuid')->assertNotFound();
});
