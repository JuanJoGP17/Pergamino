/**
 * Propiedades derivadas de los tipos de rol — espejo de
 * app/Domain/Sheet/FieldDerivation.php. Mismo algoritmo y mismo orden de
 * operaciones; SheetParityTest compara los dos sobre hojas enteras.
 *
 *   resource      {max, pct}
 *   track         {boxes, marked}
 *   progress      {level, next, pct}
 *   currency      {total}
 *   proficiency   {bonus}
 *   derived_list  {item: {bonus}}
 *   repeater      [{columna_calculada: valor}]
 */

import { number, toNumber } from './value.js'

export const MAX_TRACK_BOXES = 30

const TYPES = ['resource', 'track', 'progress', 'currency', 'proficiency', 'derived_list', 'repeater']

/** ¿Calcula algo este campo? Espejo de FieldDerivation::applies(). */
export function derives(field) {
  if (field.type === 'repeater') return (field.derived?.columns || []).length > 0
  return TYPES.includes(field.type)
}

/** Espejo de FieldValue::trackLength(). */
export function trackLength(config = {}) {
  if (config.boxes_formula) return MAX_TRACK_BOXES
  const boxes = Math.trunc(Number(config.boxes ?? 5)) || 0
  return Math.max(1, Math.min(MAX_TRACK_BOXES, boxes))
}

const isObject = (v) => v !== null && typeof v === 'object'

/**
 * @param {function(object|null, object=): *} evaluate  evalúa un AST con
 *        valores extra (p. ej. {row}); devuelve null y anota el error si falla
 */
export function deriveField(field, value, evaluate) {
  const config = field.config || {}
  const derived = field.derived || {}

  switch (field.type) {
    case 'resource': return resource(value, derived, evaluate)
    case 'track': return track(value, config, derived, evaluate)
    case 'progress': return progress(value, config)
    case 'currency': return currency(value, config)
    case 'proficiency': return { bonus: bonus(value, derived.base_ast ?? null, derived.levels || [], evaluate) }
    case 'derived_list': return derivedList(value, derived, evaluate)
    case 'repeater': return repeater(value, derived, evaluate)
    default: return null
  }
}

function resource(value, derived, evaluate) {
  const max = derived.max_ast
    ? Math.floor(toNumber(evaluate(derived.max_ast)))
    : Math.floor(toNumber(isObject(value) ? (value.max ?? 0) : 0))

  const current = toNumber(isObject(value) ? (value.current ?? 0) : 0)

  return {
    max: number(max),
    pct: max > 0 ? number(Math.floor(current * 100 / max)) : 0,
  }
}

function track(value, config, derived, evaluate) {
  const cap = trackLength(config)
  let boxes = derived.boxes_ast ? Math.floor(toNumber(evaluate(derived.boxes_ast))) : cap
  boxes = Math.max(0, Math.min(cap, boxes))

  const cells = Array.isArray(value) ? value : []
  let marked = 0
  for (let i = 0; i < boxes; i++) {
    if (toNumber(cells[i] ?? 0) > 0) marked++
  }

  return { boxes: number(boxes), marked }
}

function progress(value, config) {
  const xp = toNumber(value)
  const thresholds = (Array.isArray(config.thresholds) ? config.thresholds : []).map(toNumber)

  let level = 0
  let previous = 0
  let next = null

  for (const t of thresholds) {
    if (xp >= t) {
      level++
      previous = t
    } else if (next === null) {
      next = t
    }
  }

  let pct
  if (next === null) pct = level > 0 ? 100 : 0
  else if (next <= previous) pct = 0
  else pct = number(Math.floor((xp - previous) * 100 / (next - previous)))

  return { level, next: next === null ? null : number(next), pct }
}

function currency(value, config) {
  const amounts = isObject(value) ? value : {}
  let total = 0

  for (const d of config.denominations || []) {
    total += toNumber(amounts[d.key ?? ''] ?? 0) * toNumber(d.rate ?? 1)
  }

  return { total: number(total) }
}

function bonus(value, baseAst, levels, evaluate) {
  const v = isObject(value) ? value : {}
  const level = v.level ?? null
  let levelBonus = 0

  for (const l of levels) {
    if ((l.key ?? null) === level) {
      levelBonus = toNumber(evaluate(l.bonus_ast ?? null))
      break
    }
  }

  return number(toNumber(evaluate(baseAst)) + levelBonus + toNumber(v.misc ?? 0))
}

function derivedList(value, derived, evaluate) {
  const v = isObject(value) ? value : {}
  const out = {}

  for (const item of derived.items || []) {
    out[item.key] = { bonus: bonus(v[item.key] ?? null, item.base_ast ?? null, derived.levels || [], evaluate) }
  }

  return out
}

/** Columnas calculadas fila a fila; en la fórmula, `@row` es la fila. */
function repeater(value, derived, evaluate) {
  const rows = Array.isArray(value) ? value : []

  return rows.map((raw) => {
    const row = isObject(raw) && !Array.isArray(raw) ? raw : {}
    const cells = {}

    for (const column of derived.columns || []) {
      // Como `$row + $cells` en PHP: si una clave estuviera en los dos, gana la fila.
      cells[column.key] = evaluate(column.ast, { row: { ...cells, ...row } })
    }

    return cells
  })
}
