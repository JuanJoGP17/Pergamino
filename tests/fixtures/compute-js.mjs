/**
 * Recalcula hojas completas con el motor de JavaScript.
 *
 *   echo '{"schema": {...}, "cases": [{...valores...}]}' | node tests/fixtures/compute-js.mjs
 *
 * Lo usa tests/Feature/SheetParityTest.php: le pasa el esquema compilado de una
 * plantilla real y varias hojas, y compara la salida con SheetCalculator. Es la
 * misma idea que diff-engines.sh, pero a nivel de hoja entera: orden
 * topológico, mod_formula, condiciones, tiradas y formato de presentación.
 */

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const load = (rel) => import(pathToFileURL(join(here, '../../resources/js/formula', rel)).href)

const { computeAll, interpolate, isReadonly, isSectionVisible, isVisible } = await load('evaluate.js')
const { formatModifier, formatValue } = await load('sheet.js')

const { schema, cases } = JSON.parse(readFileSync(0, 'utf8'))
const settings = schema.settings || {}

const out = cases.map((values) => {
  const { computed, errors } = computeAll(schema, values)
  const result = { computed, errors, visible: {}, readonly: {}, sections: {}, rolls: {}, display: {} }

  for (const [key, field] of Object.entries(schema.fields)) {
    result.visible[key] = isVisible(field, values, computed, settings)
    result.readonly[key] = isReadonly(field, values, computed, settings)

    if (field.roll) result.rolls[key] = interpolate(field.roll, values, computed, settings)

    if (field.type === 'computed') {
      result.display[key] = formatValue(computed[key] ?? null, (field.config || {}).format)
    } else if (field.type === 'attribute') {
      result.display[key] = formatModifier(computed[key]?.mod ?? null)
    }
  }

  for (const tab of schema.tabs || []) {
    for (const section of tab.sections) {
      result.sections[section.key] = isSectionVisible(section, values, computed, settings)
    }
  }

  return result
})

process.stdout.write(JSON.stringify(out))
