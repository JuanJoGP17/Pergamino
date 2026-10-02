{{--
    Motivo por el que una fórmula de este campo no pudo calcularse. El campo
    queda en «—» y la hoja sigue abriendo: en plena partida, un campo vacío con
    explicación es mucho mejor que una hoja rota.
--}}
<p class="mt-1 text-[0.65rem] text-[var(--pg-accent)]"
   x-show="error(@js($key))" x-text="'⚠ ' + error(@js($key))"
   @style(['display: none' => ! $formulaError])>⚠ {{ $formulaError }}</p>
