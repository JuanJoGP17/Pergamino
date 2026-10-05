<?php

use App\Domain\Campaign\Campaigns;
use App\Domain\Dice\RollDice;
use App\Domain\Sheet\CreateSheet;
use App\Livewire\Campaign\Index;
use App\Livewire\Campaign\InitiativeTracker;
use App\Livewire\Campaign\Party;
use App\Livewire\Campaign\RollLog;
use App\Livewire\Campaign\Show;
use App\Livewire\DiceTray;
use App\Livewire\Sheet\Editor;
use App\Livewire\Template\Preview;
use App\Models\Campaign;
use App\Models\DiceRoll;
use App\Models\Template;
use App\Models\User;
use Database\Seeders\Dnd5eTemplateSeeder;
use Livewire\Livewire;

/**
 * Mesas y dados en la interfaz (Fase 6): lo que hace cada pantalla.
 */
beforeEach(function () {
    $this->gm = User::factory()->create(['name' => 'Dana DJ']);
    $this->player = User::factory()->create(['name' => 'Pau']);
    (new Dnd5eTemplateSeeder)->run();
    $this->template = Template::where('slug', 'dnd-5e')->sole();
    $this->campaign = app(Campaigns::class)->create($this->gm, 'La cripta', null, $this->template->id);
    app(Campaigns::class)->join($this->player, $this->campaign->join_code);
    $this->sheet = app(CreateSheet::class)($this->template, $this->player, 'Eldra');
    app(Campaigns::class)->addSheet($this->campaign, $this->player, $this->sheet, 'summary');
});

it('crea una mesa y se une otra persona con el código o con el enlace', function () {
    $other = User::factory()->create();

    Livewire::actingAs($other)->test(Index::class)
        ->set('name', 'Mi mesa')
        ->call('create')
        ->assertRedirect(route('campaigns.show', Campaign::where('name', 'Mi mesa')->sole()));

    Livewire::actingAs($other)->test(Index::class)
        ->set('code', 'no-existe')->call('join')->assertHasErrors('code')
        ->set('code', strtolower($this->campaign->join_code))->call('join')
        ->assertRedirect(route('campaigns.show', $this->campaign));

    $third = User::factory()->create();
    $this->actingAs($third)->get(route('campaigns.join', $this->campaign->join_code))
        ->assertRedirect(route('campaigns.show', $this->campaign));
    expect($this->campaign->roleOf($third))->toBe('player');
});

it('la mesa solo la ve quien está en ella', function () {
    $this->actingAs($this->player)->get(route('campaigns.show', $this->campaign))->assertOk()->assertSee('La cripta');
    $this->actingAs(User::factory()->create())->get(route('campaigns.show', $this->campaign))->assertForbidden();

    // Tampoco los componentes hijos, que son endpoints por su cuenta.
    Livewire::actingAs(User::factory()->create())->test(RollLog::class, ['campaignUuid' => $this->campaign->uuid])->assertForbidden();
});

it('el DJ ve el código; el grupo enseña la tarjeta resumen', function () {
    Livewire::actingAs($this->gm)->test(Show::class, ['campaign' => $this->campaign])
        ->assertSee($this->campaign->join_code)
        ->assertSee('Ajustes');

    Livewire::actingAs($this->player)->test(Show::class, ['campaign' => $this->campaign])
        ->assertDontSee($this->campaign->join_code)
        ->assertSee('Crear mi hoja de D&amp;D 5e', false);

    Livewire::actingAs($this->gm)->test(Party::class, ['campaignUuid' => $this->campaign->uuid])
        ->assertSee('Eldra')
        ->assertSee('Clase de armadura')
        ->assertSee('Pau');
});

it('el jugador crea su hoja con la plantilla sugerida, ya dentro de la mesa', function () {
    $this->sheet->delete();

    Livewire::actingAs($this->player)->test(Show::class, ['campaign' => $this->campaign])
        ->call('createSheet')
        ->assertRedirect();

    expect($this->campaign->sheets()->where('owner_id', $this->player->id)->count())->toBe(1);
});

it('un jugador no ve las hojas ocultas de otros', function () {
    $other = User::factory()->create();
    app(Campaigns::class)->join($other, $this->campaign->join_code);

    Livewire::actingAs($this->player)->test(Party::class, ['campaignUuid' => $this->campaign->uuid])
        ->call('setShareLevel', $this->sheet->uuid, 'hidden');

    Livewire::actingAs($other)->test(Party::class, ['campaignUuid' => $this->campaign->uuid])->assertDontSee('Eldra');
    Livewire::actingAs($this->gm)->test(Party::class, ['campaignUuid' => $this->campaign->uuid])->assertSee('Eldra');
});

it('el DJ lleva la iniciativa y los demás solo la ven', function () {
    Livewire::actingAs($this->gm)->test(InitiativeTracker::class, ['campaignUuid' => $this->campaign->uuid])
        ->set('name', 'Goblin')->set('value', '12')->call('add')
        ->call('addSheets')
        ->assertSee('Goblin')
        ->assertSee('Eldra')
        ->call('next')
        ->assertSee('Ronda 1');

    Livewire::actingAs($this->player)->test(InitiativeTracker::class, ['campaignUuid' => $this->campaign->uuid])
        ->assertSee('Goblin')
        ->assertDontSee('Siguiente turno')
        ->call('next')
        ->assertSee('La iniciativa la lleva el DJ');
});

it('tira desde un campo de la hoja: en el servidor, en la mesa elegida y a la bandeja', function () {
    $editor = Livewire::actingAs($this->player)->test(Editor::class, ['sheet' => $this->sheet])
        ->assertSet('rollCampaign', $this->campaign->uuid)
        ->call('rollField', 'iniciativa', 'advantage')
        ->assertDispatched('dice-rolled');

    $roll = DiceRoll::sole();
    expect($roll->campaign_id)->toBe($this->campaign->id)
        ->and($roll->sheet_id)->toBe($this->sheet->id)
        ->and($roll->label)->toBe('Eldra · Iniciativa')
        ->and($roll->expression)->toBe('1d20 + 0')
        ->and($roll->mode)->toBe('advantage')
        ->and($roll->result['groups'][0]['notation'])->toBe('2d20kh1');

    // Sin mesa: la tirada va al historial propio.
    $editor->set('rollCampaign', '')->call('rollField', 'salv_fuerza');
    expect(DiceRoll::latest('id')->first()->campaign_id)->toBeNull();

    // Un campo sin tirada no hace nada.
    $editor->call('rollField', 'nivel');
    expect(DiceRoll::count())->toBe(2);
});

it('quien solo puede ver la hoja no tira con ella', function () {
    Livewire::actingAs(User::factory()->create())->test(Editor::class, ['sheet' => $this->sheet])->assertForbidden();

    $this->campaign->sheets()->updateExistingPivot($this->sheet->id, ['share_level' => 'full']);
    $other = User::factory()->create();
    app(Campaigns::class)->join($other, $this->campaign->join_code);

    Livewire::actingAs($other)->test(Editor::class, ['sheet' => $this->sheet->fresh()])
        ->call('rollField', 'iniciativa')
        ->assertForbidden();
});

it('la vista previa del constructor tira sin guardar nada', function () {
    $this->template->update(['owner_id' => $this->gm->id]);

    Livewire::actingAs($this->gm)->test(Preview::class, ['templateUuid' => $this->template->uuid])
        ->call('rollField', 'iniciativa')
        ->assertDispatched('dice-rolled', fn ($name, $params) => $params['roll']['label'] === 'Vista previa · Iniciativa');

    expect(DiceRoll::count())->toBe(0);
});

it('la bandeja tira a mano, en secreto si es el DJ, y lo enseña en el registro de la mesa', function () {
    Livewire::actingAs($this->gm)->test(DiceTray::class)
        ->set('expression', '1d20+5')
        ->set('campaign', $this->campaign->uuid)
        ->set('private', true)
        ->call('roll')
        ->assertSet('open', true)
        ->assertSee('1d20+5')
        ->set('expression', 'no son dados')->call('roll')
        ->assertSet('error', 'La tirada lleva caracteres que no son de dados.');

    app(RollDice::class)($this->player, '2d6', 'Daño', campaign: $this->campaign);

    Livewire::actingAs($this->gm)->test(RollLog::class, ['campaignUuid' => $this->campaign->uuid])
        ->assertSee('Daño')
        ->assertSeeHtml('title="Tirada secreta del DJ"');

    Livewire::actingAs($this->player)->test(RollLog::class, ['campaignUuid' => $this->campaign->uuid])
        ->assertSee('Daño')
        ->assertDontSeeHtml('title="Tirada secreta del DJ"')
        ->set('who', (string) $this->gm->id)
        ->assertDontSee('Daño');
});

it('las notas se escriben en markdown seguro y las privadas solo las ve el DJ', function () {
    Livewire::actingAs($this->gm)->test(Show::class, ['campaign' => $this->campaign])
        ->set('noteTitle', 'Secreto')->set('noteBody', 'El **posadero** <script>alert(1)</script>')->set('noteGmOnly', true)
        ->call('saveNote')
        ->assertSeeHtml('<strong>posadero</strong>')
        ->assertDontSeeHtml('<script>alert(1)</script>');

    Livewire::actingAs($this->player)->test(Show::class, ['campaign' => $this->campaign])
        ->assertDontSee('posadero');
});

it('dentro de una mesa, la bandeja tira en ella por defecto', function () {
    Livewire::actingAs($this->player)->test(Show::class, ['campaign' => $this->campaign])
        ->assertDispatched('campaign-opened', uuid: $this->campaign->uuid);

    Livewire::actingAs($this->player)->test(DiceTray::class)
        ->dispatch('campaign-opened', uuid: $this->campaign->uuid)
        ->assertSet('campaign', $this->campaign->uuid)
        // Una mesa ajena (o algo que no es un uuid) no cambia nada.
        ->dispatch('campaign-opened', uuid: app(Campaigns::class)->create(User::factory()->create(), 'Ajena')->uuid)
        ->assertSet('campaign', $this->campaign->uuid)
        ->dispatch('campaign-opened', uuid: 'no-es-un-uuid')
        ->assertSet('campaign', $this->campaign->uuid);
});
