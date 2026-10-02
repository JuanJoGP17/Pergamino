/**
 * Ejecuta formula-cases.json contra el motor de JavaScript.
 * Su gemelo es run-php.php. Los dos DEBEN dar el mismo resultado.
 *
 *     node tests/fixtures/run-js.mjs
 */

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
// import() exige una URL: una ruta de Windows («D:\…») se leería como el
// esquema «d:» y fallaría con ERR_UNSUPPORTED_ESM_URL_SCHEME.
const { evaluate } = await import(pathToFileURL(join(here, '../../resources/js/formula/evaluate.js')).href)

const data = JSON.parse(readFileSync(join(here, 'formula-cases.json'), 'utf8'))

// Modo volcado: ver diff-engines.sh
const dump = process.argv.includes('--dump')

let pass = 0
let fail = 0
const failures = []

for (const c of data.cases) {
  const label = `[${c.group}] ${c.formula}`

  // Los casos de error de sintaxis no aplican: JS no analiza, solo evalúa.
  if (c.phase === 'compile') continue

  if (dump) {
    try {
      const value = evaluate(c.ast, data.values, data.computed, data.settings)
      console.log(JSON.stringify({ f: c.formula, v: value }))
    } catch {
      console.log(JSON.stringify({ f: c.formula, err: true }))
    }
    continue
  }

  try {
    const actual = evaluate(c.ast, data.values, data.computed, data.settings)

    if (c.error) {
      fail++
      failures.push(`${label} → debería lanzar error, devolvió ${JSON.stringify(actual)}`)
      continue
    }

    if (JSON.stringify(actual) === JSON.stringify(c.expect)) {
      pass++
    } else {
      fail++
      failures.push(`${label} → esperado ${JSON.stringify(c.expect)}, obtenido ${JSON.stringify(actual)}`)
    }
  } catch (e) {
    if (c.error) {
      pass++
    } else {
      fail++
      failures.push(`${label} → lanzó: ${e.message}`)
    }
  }
}

if (dump) process.exit(0)   // en modo volcado no se imprime resumen

for (const f of failures) console.log(`  FAIL ${f}`)
console.log(fail === 0 ? `JS   — ${pass} casos OK` : `JS   — ${pass} ok, ${fail} FALLOS`)

process.exit(fail === 0 ? 0 : 1)
