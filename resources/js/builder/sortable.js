/**
 * Directiva Alpine `x-sortable`: SortableJS para el constructor (§6.1).
 *
 *   <div x-sortable="{ group: 'fields', item: 'field', container: 12,
 *                      move: (id, to, index) => $wire.moveField(id, to, index),
 *                      add: (type, to, index) => $wire.addFieldAt(type, to, index) }">
 *     <div data-field-id="5">…</div>
 *   </div>
 *
 *   <div x-sortable="{ group: 'fields', clone: true }">     ← la paleta
 *     <div data-palette-type="number">Número</div>
 *   </div>
 *
 * Cada nivel marca sus elementos con su propio atributo (data-tab-id,
 * data-section-id, data-field-id): los campos viven DENTRO de las secciones, y
 * con un atributo común la lista de secciones confundiría un campo con una
 * sección.
 *
 * Reordenar es optimista: el elemento se queda donde se soltó y el servidor
 * confirma. Livewire re-renderiza después, y como cada elemento lleva wire:key,
 * el resultado coincide con lo que ya se ve. Si el servidor rechaza el cambio,
 * ese mismo re-render devuelve el elemento a su sitio.
 *
 * forceFallback: arrastre con eventos de ratón y no con el drag & drop nativo
 * de HTML5. Se comporta igual en todos los navegadores, funciona con el dedo y
 * se puede automatizar en pruebas.
 */

import Sortable from 'sortablejs'

export default function registerSortable(Alpine) {
  Alpine.directive('sortable', (el, { expression }, { evaluate, cleanup }) => {
    const options = evaluate(expression)
    el._pgSortable = options

    const sortable = Sortable.create(el, {
      group: options.clone
        ? { name: options.group, pull: 'clone', put: false }
        : { name: options.group, pull: true, put: true },
      sort: !options.clone,
      draggable: options.clone ? '[data-palette-type]' : `[data-${options.item}-id], [data-palette-type]`,
      handle: options.handle,
      filter: options.filter ?? 'input, textarea, select, button',
      preventOnFilter: false,
      animation: 150,
      forceFallback: true,
      fallbackOnBody: true,
      emptyInsertThreshold: 24,
      ghostClass: 'pg-sortable-ghost',
      chosenClass: 'pg-sortable-chosen',

      // Un elemento de la paleta soltado en una lista: no es un elemento real,
      // es una orden de «crea aquí un campo de este tipo».
      onAdd(evt) {
        const type = evt.item.dataset.paletteType
        if (!type) return

        evt.item.remove()
        el._pgSortable.add?.(type, el._pgSortable.container, evt.newDraggableIndex)
      },

      // Movimiento de un elemento real, dentro de su lista o a otra del mismo
      // grupo. Se avisa desde la lista de origen, que es la que lo sabe todo.
      onEnd(evt) {
        if (options.clone) return

        const id = Number(evt.item.dataset[`${options.item}Id`])
        const target = evt.to._pgSortable
        if (!id || !target) return
        if (evt.from === evt.to && evt.oldDraggableIndex === evt.newDraggableIndex) return

        options.move?.(id, target.container, evt.newDraggableIndex)
      },
    })

    cleanup(() => sortable.destroy())
  })
}
