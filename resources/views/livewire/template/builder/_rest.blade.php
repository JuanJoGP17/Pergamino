{{-- Cuándo se recupera un recurso o un contador (ver App\Domain\Sheet\TakeRest). --}}
<div>
    <label class="pg-label" for="i-config-reset_on">Se recupera con</label>
    <select id="i-config-reset_on" class="pg-input" wire:model.live="form.config.reset_on">
        <option value="">Nada</option>
        <option value="short">Descanso corto</option>
        <option value="long">Descanso largo</option>
    </select>
</div>
