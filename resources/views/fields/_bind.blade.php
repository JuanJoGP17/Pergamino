{{--
    Atributos comunes de un control editable, para escribir DENTRO de la etiqueta:
      · data-formula-key: el recalculo del navegador lee de aquí el valor.
      · data-formula-path: la parte de un valor compuesto («current», «2.peso»).
        Se pasa como 'bindPath' en el @include.
      · readonly_if: deshabilitado según el servidor y, desde ahí, según el
        navegador en cada pulsación. SaveSheet lo vuelve a imponer al guardar.

    Para un botón (una casilla de estrés, el + de un contador) se pasa
    'bindKey' => false: solo interesa el bloqueo, no hay valor que leer.

    Sin @if a propósito: Livewire envuelve cada @if en marcadores
    <!--[if BLOCK]-->, y dentro de una etiqueta romperían el HTML.
--}}
@php
    $bind = [];

    if ($bindKey ?? true) {
        $bind['data-formula-key'] = $key;
        $bind['data-formula-path'] = isset($bindPath) ? (string) $bindPath : false;
    }

    if (! empty($field['readonly_ast'])) {
        $bind['x-bind:disabled'] = 'readonly('.\Illuminate\Support\Js::from($key).')';
        $bind['disabled'] = (bool) ($isReadonly ?? false);
    }
@endphp
{{ new \Illuminate\View\ComponentAttributeBag(array_filter($bind, fn ($v) => $v !== false)) }}
