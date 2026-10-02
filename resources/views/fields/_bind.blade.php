{{--
    Atributos comunes de un input editable, para escribir DENTRO de la etiqueta:
      · data-formula-key: el recalculo del navegador lee de aquí el valor.
      · readonly_if: deshabilitado según el servidor y, desde ahí, según el
        navegador en cada pulsación. SaveSheet lo vuelve a imponer al guardar.

    Sin @if a propósito: Livewire envuelve cada @if en marcadores
    <!--[if BLOCK]-->, y dentro de una etiqueta romperían el HTML.
--}}
@php
    $bind = ['data-formula-key' => $key];

    if (! empty($field['readonly_ast'])) {
        $bind['x-bind:disabled'] = 'readonly('.\Illuminate\Support\Js::from($key).')';
        $bind['disabled'] = (bool) ($isReadonly ?? false);
    }
@endphp
{{ new \Illuminate\View\ComponentAttributeBag(array_filter($bind, fn ($v) => $v !== false)) }}
