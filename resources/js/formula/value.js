/**
 * Coerciones del lenguaje de fórmulas — espejo de app/Domain/Formula/Value.php.
 *
 * ⚠ ARCHIVO CRÍTICO. Cualquier cambio aquí tiene que replicarse en el PHP y
 * quedar cubierto por tests/fixtures/formula-cases.json, que ejecuta los mismos
 * casos contra los dos motores. Si divergen, la hoja mostraría un número
 * mientras escribes y otro distinto al guardar.
 *
 * Las reglas están documentadas en app/Domain/Formula/README.md.
 */

/**
 * Réplica exacta de is_numeric() de PHP.
 *
 * Number() de JS acepta cosas que PHP no considera numéricas: "0x10" → 16,
 * "0b101" → 5, "Infinity" → Infinity. Usarlo directamente hacía que
 * `"0x10" + 0` diera 16 en el navegador y "0x100" en el servidor. Esta regexp
 * acepta lo mismo que PHP: entero o decimal, signo opcional, exponente
 * opcional. Nada más.
 */
const PHP_NUMERIC = /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/

function isNumericString(v) {
  return PHP_NUMERIC.test(v.trim())
}

/**
 * Normaliza un número. En JS todos los números son dobles, así que aquí solo
 * hay que evitar el -0 y dejar pasar los no finitos; el trabajo de "entero si
 * es integral" lo hace PHP en su lado para que el JSON coincida.
 */
export function number(n) {
  if (typeof n !== 'number') n = Number(n)
  if (!Number.isFinite(n)) return n
  if (Object.is(n, -0)) return 0
  return n
}

export function toNumber(v) {
  if (typeof v === 'number') return number(v)
  if (v === null || v === undefined || v === false) return 0
  if (v === true) return 1

  if (typeof v === 'string') {
    // Se exige que sea numérico según la regla de PHP, no la de JS.
    return isNumericString(v) ? number(Number(v.trim())) : 0
  }

  if (Array.isArray(v)) return 0
  if (typeof v === 'object') return 0
  return 0
}

export function toString(v) {
  if (typeof v === 'string') return v
  if (v === null || v === undefined) return ''
  if (typeof v === 'boolean') return v ? 'true' : 'false'

  if (typeof v === 'number') {
    if (!Number.isFinite(v)) return String(v)
    if (Number.isInteger(v)) return String(v)
    // Sin notación científica ni ceros de relleno, igual que el PHP.
    return String(parseFloat(v.toFixed(10)))
  }

  if (Array.isArray(v)) return v.map(toString).join(',')
  return ''
}

export function toBool(v) {
  if (typeof v === 'boolean') return v
  if (v === null || v === undefined) return false
  if (typeof v === 'number') return v !== 0
  if (typeof v === 'string') return v !== ''   // ojo: "0" es truthy
  if (Array.isArray(v)) return v.length > 0
  return true
}

export function toArray(v) {
  if (Array.isArray(v)) return v
  if (v === null || v === undefined) return []
  return [v]
}

function isNumericish(v) {
  if (typeof v === 'number' || typeof v === 'boolean') return true
  if (v === null || v === undefined) return true
  if (typeof v === 'string') return isNumericString(v)
  return false
}

export function looseEquals(a, b) {
  if (Array.isArray(a) || Array.isArray(b)) {
    const x = toArray(a)
    const y = toArray(b)
    return x.length === y.length && x.every((item, i) => looseEquals(item, y[i]))
  }

  const aNull = a === null || a === undefined
  const bNull = b === null || b === undefined
  if (aNull && bNull) return true

  if (isNumericish(a) && isNumericish(b)) return toNumber(a) === toNumber(b)
  if (typeof a === 'boolean' || typeof b === 'boolean') return toBool(a) === toBool(b)
  return toString(a) === toString(b)
}

export function compare(a, b) {
  if (isNumericish(a) && isNumericish(b)) {
    const x = toNumber(a)
    const y = toNumber(b)
    return x < y ? -1 : x > y ? 1 : 0
  }

  // strcmp de PHP compara byte a byte; se replica con la comparación de
  // unidades de código, normalizando el resultado a -1/0/1.
  const x = toString(a)
  const y = toString(b)
  return x < y ? -1 : x > y ? 1 : 0
}

export { isNumericString }

export function contains(haystack, needle) {
  if (Array.isArray(haystack)) {
    return haystack.some((item) => looseEquals(item, needle))
  }
  if (haystack === null || haystack === undefined) return false

  const s = toString(haystack)
  const n = toString(needle)
  return n !== '' && s.includes(n)
}
