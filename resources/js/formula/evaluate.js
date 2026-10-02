/**
 * Evaluador de fórmulas en el navegador — espejo de
 * app/Domain/Formula/Evaluator.php.
 *
 * Aquí NO hay analizador sintáctico, y es a propósito: el AST llega ya
 * construido dentro de compiled_schema, generado por el PHP al publicar la
 * versión de plantilla. Ver app/Domain/Formula/README.md.
 *
 * Uso:
 *   import { evaluate, computeAll } from './formula/evaluate.js'
 *   computeAll(schema, values)   → { fuerza: {mod: 3}, ca: 15, … }
 */

import { callFunction, checkArity, FormulaRuntimeError } from './functions.js'
import {
  compare, contains, isNumericString, looseEquals, number,
  toArray, toBool, toNumber, toString,
} from './value.js'

export { FormulaRuntimeError }

class Evaluator {
  constructor(values = {}, computed = {}, settings = {}) {
    this.values = values
    this.computed = computed
    this.settings = settings
  }

  evaluate(node) {
    switch (node.n) {
      case 'num':
      case 'str':
      case 'bool':
        return node.v
      case 'null':
        return null
      case 'arr':
        return node.items.map((item) => this.evaluate(item))
      case 'ref':
        return this.resolveReference(node)
      case 'un':
        return this.unary(node)
      case 'bin':
        return this.binary(node)
      case 'ter':
        return toBool(this.evaluate(node.c)) ? this.evaluate(node.a) : this.evaluate(node.b)
      case 'call':
        return this.call(node)
      default:
        throw new FormulaRuntimeError(`nodo desconocido: ${node.n}`)
    }
  }

  resolveReference(node) {
    let key = node.key
    let path = node.path || []
    const idx = node.idx ?? null

    // @self.nivel es azúcar para @nivel (ver Ast::SELF en PHP).
    if (key === 'self') {
      if (path.length === 0) return null
      path = path.slice()
      key = path.shift()
    }

    // Los derivados tienen prioridad para la propiedad: @fuerza da 16 (valor
    // crudo) pero @fuerza.mod da 3 (derivado).
    if (path.length > 0 && this.computed[key] !== undefined) {
      const found = digPath(this.computed[key], path)
      if (found !== null && found !== undefined) return found
    }

    let base = this.values[key]
    if (base === undefined) base = this.computed[key]
    if (base === undefined) base = null

    if (idx !== null) {
      const rows = toArray(base)

      if (idx === '*') {
        return rows.map((row) => digPath(row, path))
      }

      const i = Math.trunc(toNumber(this.evaluate(idx)))
      base = rows[i] ?? null
    }

    return path.length === 0 ? base : digPath(base, path)
  }

  unary(node) {
    const x = this.evaluate(node.x)
    switch (node.op) {
      case '-': return negate(x)
      case '!': return !toBool(x)
      default: throw new FormulaRuntimeError(`operador unario desconocido: ${node.op}`)
    }
  }

  binary(node) {
    const op = node.op

    // Cortocircuito, para que `@nivel > 0 && 10 / @nivel > 1` no divida por cero.
    if (op === '&&') {
      return toBool(this.evaluate(node.l)) ? toBool(this.evaluate(node.r)) : false
    }
    if (op === '||') {
      return toBool(this.evaluate(node.l)) ? true : toBool(this.evaluate(node.r))
    }

    const l = this.evaluate(node.l)
    const r = this.evaluate(node.r)

    switch (op) {
      case '+':
      case '-':
      case '*':
      case '/':
      case '%':
        return arithmetic(op, l, r)
      case '==': return looseEquals(l, r)
      case '!=': return !looseEquals(l, r)
      case '<': return compare(l, r) < 0
      case '<=': return compare(l, r) <= 0
      case '>': return compare(l, r) > 0
      case '>=': return compare(l, r) >= 0
      case 'in': return contains(r, l)
      case 'contains': return contains(l, r)
      default: throw new FormulaRuntimeError(`operador desconocido: ${op}`)
    }
  }

  call(node) {
    // `if` es perezoso: solo se evalúa la rama elegida.
    if (node.fn === 'if') {
      checkArity('if', node.args.length)
      return toBool(this.evaluate(node.args[0]))
        ? this.evaluate(node.args[1])
        : this.evaluate(node.args[2])
    }

    const args = node.args.map((arg) => this.evaluate(arg))
    return callFunction(node.fn, args, this.settings)
  }
}

function digPath(value, path) {
  let current = value
  for (const segment of path) {
    if (current !== null && typeof current === 'object' && segment in current) {
      current = current[segment]
    } else {
      return null
    }
  }
  return current
}

/**
 * Aritmética con difusión sobre listas — espejo de Evaluator::arithmetic().
 * [3, 2] * [1, 5] → [3, 10];  [3, 2] * 2 → [6, 4]. La lista corta se completa
 * con null (que vale 0).
 */
function arithmetic(op, l, r) {
  const la = Array.isArray(l)
  const ra = Array.isArray(r)

  if (la || ra) {
    const len = Math.max(la ? l.length : 0, ra ? r.length : 0)
    const out = []
    for (let i = 0; i < len; i++) {
      out.push(arithmetic(op, la ? (l[i] ?? null) : l, ra ? (r[i] ?? null) : r))
    }
    return out
  }

  switch (op) {
    case '+': return add(l, r)
    case '-': return number(toNumber(l) - toNumber(r))
    case '*': return number(toNumber(l) * toNumber(r))
    case '/': return divide(l, r)
    case '%': return modulo(l, r)
  }
}

function negate(x) {
  if (Array.isArray(x)) return x.map(negate)
  return number(-toNumber(x))
}

/** Texto que NO es un número según la regla de PHP (ver value.js). */
function isTextual(v) {
  return typeof v === 'string' && v.trim() !== '' && !isNumericString(v)
}

/** `+` suma, o concatena si alguno de los dos es texto no numérico. */
function add(l, r) {
  if (isTextual(l) || isTextual(r)) return toString(l) + toString(r)
  return number(toNumber(l) + toNumber(r))
}

function divide(l, r) {
  const divisor = toNumber(r)
  if (divisor === 0) throw new FormulaRuntimeError('división por cero')
  return number(toNumber(l) / divisor)
}

function modulo(l, r) {
  const divisor = toNumber(r)
  if (divisor === 0) throw new FormulaRuntimeError('división por cero')
  // El % de JS ya se comporta como fmod de PHP: conserva el signo del dividendo.
  return number(toNumber(l) % divisor)
}

// ---------------------------------------------------------------- API pública

export function evaluate(ast, values = {}, computed = {}, settings = {}) {
  if (!ast) return null
  return new Evaluator(values, computed, settings).evaluate(ast)
}

/**
 * Recalcula TODOS los derivados de una hoja, en el orden topológico que ya
 * viene resuelto dentro del esquema.
 *
 * Es el gemelo de SheetCalculator::calculate(). Lo llama el editor al cambiar
 * un valor, para pintar el resultado sin esperar al servidor; el servidor
 * recalcula igualmente al guardar y su resultado manda.
 *
 * @param {object} schema  compiled_schema de la versión de plantilla
 * @param {object} values  valores actuales de la hoja
 * @returns {{computed: object, errors: object}}
 */
export function computeAll(schema, values) {
  const computed = {}
  const errors = {}
  const settings = schema.settings || {}
  const fields = schema.fields || {}

  // 1) Propiedades derivadas incorporadas al tipo de campo. Los atributos con
  //    mod_formula se calculan en el paso 2, en su turno.
  for (const [key, field] of Object.entries(fields)) {
    if (field.type === 'attribute' && !field.mod_ast) {
      computed[key] = { mod: attributeModifier(values[key], field.config || {}, settings) }
    }
  }

  // 2) Campos con fórmula, en orden topológico: cada uno ve ya calculados a
  //    todos aquellos de los que depende.
  for (const key of schema.compute_order || []) {
    const field = fields[key]
    if (!field) continue

    if (field.mod_ast) {
      try {
        computed[key] = { mod: evaluate(field.mod_ast, values, computed, settings) }
      } catch (e) {
        computed[key] = { mod: null }
        errors[key] = e instanceof Error ? e.message : String(e)
      }
    }

    if (!field.ast) continue

    try {
      computed[key] = evaluate(field.ast, values, computed, settings)
    } catch (e) {
      computed[key] = null
      errors[key] = e instanceof Error ? e.message : String(e)
    }
  }

  return { computed, errors }
}

/**
 * Espejo de SheetCalculator::attributeModifier(): (int) del valor, base y
 * divisor del campo o, si no, de la plantilla.
 */
function attributeModifier(raw, config, settings) {
  const score = Math.trunc(toNumber(raw))
  const base = Math.trunc(Number(config.mod_base ?? settings.mod_base ?? 10))
  const divisor = Math.trunc(Number(config.mod_divisor ?? settings.mod_divisor ?? 2))
  if (divisor === 0) return 0
  return number(Math.floor((score - base) / divisor))
}

/**
 * Plantilla de tirada con los huecos resueltos — espejo de
 * Interpolator::run(): `1d20 + {@destreza.mod}` → `1d20 + 2`.
 * Los huecos llegan ya analizados; en el texto son marcadores \0N\0.
 */
export function interpolate(compiled, values, computed, settings = {}) {
  if (!compiled) return null
  const evaluator = new Evaluator(values, computed, settings)

  return compiled.template.replace(/\u0000(\d+)\u0000/g, (_, i) => {
    const ast = compiled.holes[Number(i)]
    if (!ast) return ''
    try {
      return toString(toNumber(evaluator.evaluate(ast)))
    } catch (e) {
      if (e instanceof FormulaRuntimeError) return '0'
      throw e
    }
  })
}

/** Visibilidad de una sección: sin condición, siempre. */
export function isSectionVisible(section, values, computed, settings = {}) {
  if (!section.visible_ast) return true
  try {
    return toBool(evaluate(section.visible_ast, values, computed, settings))
  } catch {
    return true
  }
}

/** ¿Debe mostrarse este campo? Fórmula vacía = siempre visible. */
export function isVisible(field, values, computed, settings = {}) {
  if (!field.visible_ast) return true
  try {
    return toBool(evaluate(field.visible_ast, values, computed, settings))
  } catch {
    return true   // ante la duda, mostrarlo: ocultar por error es peor
  }
}

export function isReadonly(field, values, computed, settings = {}) {
  if (!field.readonly_ast) return false
  try {
    return toBool(evaluate(field.readonly_ast, values, computed, settings))
  } catch {
    return false
  }
}
