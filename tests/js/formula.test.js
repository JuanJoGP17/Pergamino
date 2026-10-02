/**
 * Motor de fórmulas de JavaScript (§5.5, §12 del plan).
 *
 * La batería compartida formula-cases.json se ejecuta aquí contra el motor de
 * JS y en tests/Unit/FormulaParityTest.php contra el de PHP. Además,
 * diff-engines.sh compara las dos salidas entre sí y
 * tests/Feature/SheetParityTest.php compara hojas completas.
 */

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import { computeAll, evaluate, interpolate, isReadonly, isSectionVisible, isVisible } from '../../resources/js/formula/evaluate.js'
import { formatModifier, formatValue } from '../../resources/js/formula/sheet.js'

const fixture = JSON.parse(readFileSync(new URL('../fixtures/formula-cases.json', import.meta.url), 'utf8'))
const { values, computed, settings } = fixture
const evaluable = fixture.cases.filter((c) => c.phase === 'evaluate')

describe('batería compartida con PHP', () => {
  it('está cargada', () => {
    expect(evaluable.length).toBeGreaterThan(100)
  })

  it.each(evaluable.map((c) => [`[${c.group}] ${c.formula}`, c]))('%s', (_, c) => {
    if (c.error) {
      expect(() => evaluate(c.ast, values, computed, settings)).toThrow()
    } else {
      // Se compara el JSON, que es lo que viaja: así 3 y 3.0 no se confunden.
      expect(JSON.stringify(evaluate(c.ast, values, computed, settings))).toBe(JSON.stringify(c.expect))
    }
  })
})

// Árboles escritos a mano con la forma que produce Parser.php.
const num = (v) => ({ n: 'num', v })
const ref = (key, path = []) => ({ n: 'ref', key, path, idx: null })
const bin = (op, l, r) => ({ n: 'bin', op, l, r })
const call = (fn, ...args) => ({ n: 'call', fn, args })

describe('computeAll', () => {
  const schema = {
    settings: { mod_base: 10, mod_divisor: 2 },
    fields: {
      fuerza: { type: 'attribute', config: {} },
      // mod_formula ya atada al campo (Ast::bindSelf): floor(@vigor / 2)
      vigor: { type: 'attribute', config: {}, mod_ast: call('floor', bin('/', ref('vigor'), num(2))) },
      total: { type: 'computed', ast: bin('+', ref('fuerza', ['mod']), ref('vigor', ['mod'])) },
      roto: { type: 'computed', ast: bin('/', num(1), num(0)) },
    },
    compute_order: ['vigor', 'total', 'roto'],
  }

  it('calcula modificadores, mod_formula y calculados en orden', () => {
    const { computed: c, errors } = computeAll(schema, { fuerza: '16', vigor: 7 })

    expect(c.fuerza).toEqual({ mod: 3 })
    expect(c.vigor).toEqual({ mod: 3 })
    expect(c.total).toBe(6)
    expect(c.roto).toBeNull()
    expect(errors).toEqual({ roto: 'división por cero' })
  })

  it('usa la base y el divisor de la plantilla si el campo no los fija', () => {
    const s = { ...schema, settings: { mod_base: 0, mod_divisor: 3 } }
    expect(computeAll(s, { fuerza: 12 }).computed.fuerza).toEqual({ mod: 4 })
  })
})

describe('condiciones y tiradas', () => {
  it('evalúa visible_if, readonly_if y el visible_if de sección', () => {
    const cond = bin('>=', ref('nivel'), num(5))

    expect(isVisible({ visible_ast: cond }, { nivel: 5 }, {})).toBe(true)
    expect(isVisible({ visible_ast: cond }, { nivel: 1 }, {})).toBe(false)
    expect(isReadonly({ readonly_ast: cond }, { nivel: 9 }, {})).toBe(true)
    expect(isSectionVisible({ visible_ast: cond }, { nivel: 1 }, {})).toBe(false)
    expect(isVisible({}, {}, {})).toBe(true)
  })

  it('ante un error de fórmula, muestra y no bloquea', () => {
    const roto = bin('/', num(1), num(0))

    expect(isVisible({ visible_ast: roto }, {}, {})).toBe(true)
    expect(isReadonly({ readonly_ast: roto }, {}, {})).toBe(false)
  })

  it('interpola las plantillas de tirada', () => {
    const compiled = {
      template: '1d20 + \u00000\u0000 + \u00001\u0000',
      holes: [ref('destreza', ['mod']), bin('/', num(1), num(0))],
    }

    expect(interpolate(compiled, {}, { destreza: { mod: 2 } })).toBe('1d20 + 2 + 0')
    expect(interpolate(null, {}, {})).toBeNull()
  })
})

describe('presentación', () => {
  it('formatea igual que SheetCalculator::formatValue()', () => {
    expect(formatValue(null)).toBe('—')
    expect(formatValue(true)).toBe('Sí')
    expect(formatValue(3, 'mod')).toBe('+3')
    expect(formatValue(-1, 'mod')).toBe('-1')
    expect(formatValue(2.5, 'int')).toBe('3')
    expect(formatValue(-2.5, 'int')).toBe('-3')
    expect(formatValue(50, 'percent')).toBe('50 %')
    expect(formatValue([1, 2])).toBe('1, 2')
    expect(formatModifier(0)).toBe('+0')
    expect(formatModifier(null)).toBe('—')
  })
})
