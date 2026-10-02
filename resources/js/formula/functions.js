/**
 * Lista blanca de funciones — espejo de app/Domain/Formula/FunctionRegistry.php.
 *
 * ⚠ Ver la advertencia de value.js: cualquier cambio va en los dos lados.
 */

import { number, toArray, toBool, toNumber, toString, looseEquals } from './value.js'

export const ARITY = {
  floor: [1, 1], ceil: [1, 1], round: [1, 2], abs: [1, 1],
  min: [1, null], max: [1, null], clamp: [3, 3],
  pow: [2, 2], sqrt: [1, 1], sign: [1, 1],

  sum: [1, null], avg: [1, null], count: [1, 1],
  any: [1, 1], all: [1, 1],

  if: [3, 3], coalesce: [1, null], switch: [3, null],

  concat: [0, null], upper: [1, 1], lower: [1, 1], len: [1, 1],

  mod: [1, 1], prof: [1, 1], lookup: [2, 3], tier: [2, 2],

  avg_of: [1, 1],
}

export class FormulaRuntimeError extends Error {}

export function checkArity(name, given) {
  const spec = ARITY[name]
  if (!spec) throw new FormulaRuntimeError(`la función «${name}» no existe`)

  const [min, max] = spec
  if (given < min || (max !== null && given > max)) {
    const expected = max === null ? `al menos ${min}` : min === max ? `${min}` : `entre ${min} y ${max}`
    throw new FormulaRuntimeError(`la función «${name}» espera ${expected} argumento(s), recibió ${given}`)
  }
}

/**
 * Redondeo "medio hacia arriba" también con negativos: round(-0.5) = -1.
 * Math.round de JS daría 0, así que la regla se implementa a mano para casar
 * con el comportamiento de PHP.
 */
function roundHalfUp(v, precision = 0) {
  const factor = 10 ** precision
  const scaled = v * factor
  const rounded = scaled >= 0 ? Math.floor(scaled + 0.5) : -Math.floor(-scaled + 0.5)
  return rounded / factor
}

/** sum(@a, @b) y sum(@lista) deben comportarse igual. */
function flatten(args) {
  const out = []
  for (const arg of args) {
    if (Array.isArray(arg)) out.push(...arg)
    else out.push(arg)
  }
  return out
}

const numbers = (args) => flatten(args).map(toNumber)

function attributeMod(score, settings) {
  const base = settings.mod_base ?? 10
  const divisor = settings.mod_divisor ?? 2
  if (Number(divisor) === 0) return 0
  return Math.floor((score - base) / divisor)
}

function proficiency(level, settings) {
  const table = settings.proficiency_table
  if (table && typeof table === 'object') {
    const key = String(Math.trunc(level))
    if (Object.prototype.hasOwnProperty.call(table, key)) return toNumber(table[key])
  }

  const base = settings.prof_base ?? 2
  const step = settings.prof_step ?? 4
  if (Number(step) === 0) return base
  return base + Math.floor((Math.max(1, level) - 1) / step)
}

function lookup(table, key, fallback, settings) {
  const rows = (settings.lookups || {})[table]
  if (!rows || typeof rows !== 'object') return fallback
  const k = toString(key)
  return Object.prototype.hasOwnProperty.call(rows, k) ? rows[k] : fallback
}

function tier(value, thresholds) {
  let t = 0
  for (const threshold of thresholds) {
    if (value >= toNumber(threshold)) t++
    else break
  }
  return t
}

/** Valor medio de una expresión de dados, sin tirar: "2d6+3" → 10. */
function averageRoll(expression) {
  const expr = String(expression).replace(/\s+/g, '')
  const re = /([+-]?)(\d*)d(\d+)|([+-]?\d+)(?!d)/gi
  let total = 0
  let m

  while ((m = re.exec(expr)) !== null) {
    if (m[3] !== undefined) {
      const sign = m[1] === '-' ? -1 : 1
      const count = m[2] === '' ? 1 : parseInt(m[2], 10)
      const sides = parseInt(m[3], 10)
      total += (sign * count * (sides + 1)) / 2
    } else if (m[4] !== undefined) {
      total += parseFloat(m[4])
    }
  }

  return total
}

function coalesce(args) {
  for (const arg of args) {
    if (arg !== null && arg !== undefined && arg !== '') return arg
  }
  return null
}

function switchOn(args) {
  const subject = args[0]
  const rest = args.slice(1)
  const pairs = Math.floor(rest.length / 2)

  for (let i = 0; i < pairs; i++) {
    if (looseEquals(subject, rest[i * 2])) return rest[i * 2 + 1]
  }

  return rest.length % 2 === 1 ? rest[rest.length - 1] : null
}

export function callFunction(name, args, settings = {}) {
  checkArity(name, args.length)

  const n = (i) => toNumber(args[i])
  const s = (i) => toString(args[i])

  switch (name) {
    case 'floor': return number(Math.floor(n(0)))
    case 'ceil': return number(Math.ceil(n(0)))
    case 'round': return number(roundHalfUp(n(0), args.length > 1 ? Math.trunc(n(1)) : 0))
    case 'abs': return number(Math.abs(n(0)))
    case 'sqrt': return number(n(0) < 0 ? 0 : Math.sqrt(n(0)))
    case 'sign': return number(Math.sign(n(0)))
    case 'pow': return number(n(0) ** n(1))
    case 'min': return number(Math.min(...numbers(args)))
    case 'max': return number(Math.max(...numbers(args)))
    case 'clamp': return number(Math.max(n(1), Math.min(n(2), n(0))))

    case 'sum': return number(numbers(args).reduce((a, b) => a + b, 0))
    case 'avg': {
      const list = numbers(args)
      return list.length === 0 ? 0 : number(list.reduce((a, b) => a + b, 0) / list.length)
    }
    case 'count': return number(toArray(args[0]).length)
    case 'any': return toArray(args[0]).some(toBool)
    case 'all': return toArray(args[0]).every(toBool)

    case 'if': return toBool(args[0]) ? args[1] : args[2]
    case 'coalesce': return coalesce(args)
    case 'switch': return switchOn(args)

    case 'concat': return args.map(toString).join('')
    case 'upper': return s(0).toUpperCase()
    case 'lower': return s(0).toLowerCase()
    case 'len': return number(Array.isArray(args[0]) ? args[0].length : [...s(0)].length)

    case 'mod': return number(attributeMod(n(0), settings))
    case 'prof': return number(proficiency(n(0), settings))
    case 'lookup': return lookup(s(0), args[1], args.length > 2 ? args[2] : null, settings)
    case 'tier': return tier(n(0), toArray(args[1]))

    case 'avg_of': return number(averageRoll(s(0)))

    default:
      throw new FormulaRuntimeError(`la función «${name}» no existe`)
  }
}
