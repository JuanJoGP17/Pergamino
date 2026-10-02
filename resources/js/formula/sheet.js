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
      this.$wire.$watch('data', () => this.resync())
    },

    resync() {
      this.values = JSON.parse(JSON.stringify(this.$wire.data ?? {}))
      this.recompute()
    },

    /** Cualquier input con data-formula-key actualiza su valor y recalcula. */
    onInput(event) {
      const el = event.target
      const key = el?.dataset?.formulaKey
      if (!key) return

      this.values[key] = el.type === 'checkbox' ? el.checked : el.value
      this.recompute()
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
  }
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
