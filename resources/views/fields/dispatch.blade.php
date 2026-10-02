{{--
    Enrutador de campos: elige el parcial según el tipo. Añadir un tipo nuevo es
    crear su archivo aquí y añadir una línea al match — no hay que tocar el
    editor.

    Un tipo declarado en FieldType pero aún sin parcial (los de la Fase 4)
    muestra un aviso en vez de romper la hoja.
--}}
@php
    $partial = 'fields.'.$field['type'];
    $view = view()->exists($partial) ? $partial : 'fields.unsupported';
@endphp

@include($view, [
    'field' => $field,
    'key' => $key,
    'sheet' => $sheet,
    'isReadonly' => $isReadonly ?? false,
    'rollExpression' => $rollExpression ?? null,
    'formulaError' => $formulaError ?? null,
])
