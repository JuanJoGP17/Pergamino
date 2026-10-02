/**
 * Recalculo instantáneo de la hoja en el navegador (componente Alpine).
 *
 * El editor guarda en el servidor al perder el foco y el servidor recalcula:
 * ese resultado es el que manda. Esto solo existe para que, mientras escribes
 * un 18 en Fuerza, el +4 y todo lo que depende de él cambien en la misma
 * pulsación en vez de esperar al viaje de ida y vuelta.
 *
 * Recorre los MISMOS árboles que evalúa el PHP (vienen en el esquema), así que
 * no puede discrepar en gramática; las semánticas las vigila diff-engines.sh.
 *
 * Uso en Blade:
 *   <div x-data="sheetFormulas(@js($clientSchema))"
 *        @input="onInput($event)" @change="onInput($event)">
 *     <input data-formula-key="fuerza" …>
 *     <span x-text="modText('fuerza')"></span>
 */

import { computeAll, interpolate, isReadonly, isSectionVisible, isVisible } from './evaluate.js'
import { number, toNumber, toString } from './value.js'

export default function sheetFormulas(schema) {
  return {
    schema,
    values: {},
    computed: {},
    errors: {},

    init() {
      this.resync()

      // Tras cada respuesta del servidor, adoptar sus valores: él manda. Si
      // rechazó un cambio (un campo de solo lectura, por ejemplo), la vista
      // vuelve a lo que de verdad quedó guardado.
      //
      // El vigilante cuelga del componente LIVEWIRE, que sobrevive a este
      // elemento: la vista previa del constructor quita y vuelve a poner la
      // hoja. Sin darlo de baja en destroy(), quedaría uno huérfano por cada
      // vez, disparándose sobre un elemento que ya no existe.
      this.unwatch = this.$wire.$watch('data', () => this.resync())
    },

    destroy() {
      this.unwatch?.()
    },

    resync() {
      const data = this.$wire.data
      if (!data || typeof data !== 'object') return

      this.values = JSON.parse(JSON.stringify(data))
      this.recompute()
    },

    /**
     * Cualquier input con data-formula-key actualiza su valor y recalcula.
     * Los valores compuestos (un recurso, una fila de tabla) llevan además
     * data-formula-path: «current», «2.peso».
     */
    onInput(event) {
      const el = event.target
      const key = el?.dataset?.formulaKey
      if (!key) return

      this.values = withPath(this.values, key, el.dataset.formulaPath, el.type === 'checkbox' ? el.checked : el.value)
      this.recompute()
    },

    /**
     * Cambio que no viene de un input (una casilla de estrés, un segmento de
     * reloj, el + de un contador): se pinta al instante aquí y se manda al
     * componente, que guarda y devuelve lo que de verdad quedó.
     */
    set(key, path, value) {
      this.values = withPath(this.values, key, path, value)
      this.recompute()
      this.$wire.set('data.' + key + (path ? '.' + path : ''), value)
    },

    /** Valor actual de un campo o de una parte: val('pv', 'current'). */
    val(key, path = null) {
      return dig(this.values[key], path)
    },

    /** Propiedad derivada: comp('pv', 'pct'), comp('inv', '0.total'). */
    comp(key, path = null) {
      return dig(this.computed[key], path)
    },

    /** +/− con tope: adjust('pv', 'current', -1, 0, comp('pv', 'max')). */
    adjust(key, path, delta, min = null, max = null) {
      let n = toNumber(this.val(key, path)) + delta
      if (min !== null && min !== undefined && n < min) n = min
      if (max !== null && max !== undefined && n > max) n = max
      this.set(key, path, number(n))
    },

    /** Una casilla de marcas pasa al siguiente estado: vacía → 1 → … → n → vacía. */
    cycle(key, index, states) {
      const boxes = [...(this.val(key) || [])]
      boxes[index] = (toNumber(boxes[index] ?? 0) + 1) % (states + 1)
      this.set(key, null, boxes)
    },

    /** Reloj: pulsar el segmento lleno más alto lo vacía; cualquier otro rellena hasta él. */
    tick(key, segment) {
      const filled = toNumber(this.val(key))
      this.set(key, null, filled === segment + 1 ? segment : segment + 1)
    },

    /** Selección múltiple y etiquetas: poner o quitar un elemento de la lista. */
    toggle(key, item, on = null, max = 0) {
      let list = [...(this.val(key) || [])]
      const has = list.includes(item)
      if (on ?? !has) {
        if (!has && (!max || list.length < max)) list.push(item)
      } else {
        list = list.filter((v) => v !== item)
      }
      this.set(key, null, list)
    },

    addRow(key, max = 0) {
      const rows = [...(this.val(key) || [])]
      if (max && rows.length >= max) return
      rows.push({})
      this.set(key, null, rows)
    },

    removeRow(key, index) {
      this.set(key, null, (this.val(key) || []).filter((_, i) => i !== index))
    },

    recompute() {
      const { computed, errors } = computeAll(this.schema, this.values)
      this.computed = computed
      this.errors = errors
    },

    // ------------------------------------------------------------ lecturas

    field(key) {
      return this.schema.fields[key] || {}
    },

    visible(key) {
      return isVisible(this.field(key), this.values, this.computed, this.schema.settings)
    },

    readonly(key) {
      return isReadonly(this.field(key), this.values, this.computed, this.schema.settings)
    },

    sectionVisible(key) {
      const section = (this.schema.sections || {})[key] || {}
      return isSectionVisible(section, this.values, this.computed, this.schema.settings)
    },

    roll(key) {
      return interpolate(this.field(key).roll, this.values, this.computed, this.schema.settings) || ''
    },

    error(key) {
      return this.errors[key] || ''
    },

    /** Valor de un campo calculado, ya formateado para pintar. */
    display(key) {
      return formatValue(this.computed[key] ?? null, (this.field(key).config || {}).format)
    },

    modText(key) {
      return formatModifier(this.computed[key]?.mod ?? null)
    },

    /** Un número como modificador: +3 / −1. */
    modOf(value) {
      return formatModifier(value === null || value === undefined ? null : toNumber(value))
    },

    /** Un valor cualquiera como lo pinta un campo calculado. */
    fmt(value, format = null) {
      return formatValue(value ?? null, format)
    },
  }
}

// ------------------------------------------------------------ valores

/** Lee una ruta «a.b.0» dentro de un valor; null si no existe. */
function dig(value, path) {
  if (!path) return value ?? null
  let current = value
  for (const segment of String(path).split('.')) {
    if (current === null || typeof current !== 'object' || !(segment in current)) return null
    current = current[segment]
  }
  return current ?? null
}

/**
 * Copia de `values` con el valor puesto en key + ruta. Crea los objetos que
 * falten; un tramo numérico dentro de una lista es una posición.
 *
 * Un objeto vacío llega de PHP como [] (json_encode no distingue): si el
 * siguiente tramo no es numérico, esa lista se trata como objeto, o la
 * propiedad se perdería al serializar.
 */
function withPath(values, key, path, value) {
  const out = { ...values }
  if (!path) {
    out[key] = value
    return out
  }

  const segments = String(path).split('.')
  const clone = (v, next) => {
    const numeric = /^\d+$/.test(next)
    if (Array.isArray(v)) return numeric ? [...v] : Object.assign({}, v)
    if (v !== null && typeof v === 'object') return { ...v }
    return numeric ? [] : {}
  }

  out[key] = clone(out[key], segments[0])
  let node = out[key]
  segments.forEach((segment, i) => {
    if (i === segments.length - 1) {
      node[segment] = value
    } else {
      node[segment] = clone(node[segment], segments[i + 1])
      node = node[segment]
    }
  })
  return out
}

// ------------------------------------------------------------- presentación
// Espejo de SheetCalculator::formatValue() / formatModifier(). Si no
// coincidieran, el número «saltaría» al volver la respuesta del servidor.

export function formatModifier(mod) {
  if (mod === null || mod === undefined) return '—'
  return (mod >= 0 ? '+' : '') + toString(mod)
}

export function formatValue(value, format = null) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  if (Array.isArray(value)) return value.map((v) => formatValue(v, format)).join(', ')

  switch (format) {
    case 'int': {
      const n = toNumber(value)
      return toString(number(n >= 0 ? Math.floor(n + 0.5) : -Math.floor(-n + 0.5)))
    }
    case 'mod': return formatModifier(toNumber(value))
    case 'percent': return `${toString(value)} %`
    default: return toString(value)
  }
}
