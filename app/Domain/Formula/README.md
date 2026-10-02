# Motor de fórmulas

## La decisión que evita el peor riesgo del proyecto

El plan (§16) identificaba como riesgo principal que **los dos motores de
fórmulas divergieran**: uno en PHP (autoritativo) y otro en JS (feedback
instantáneo), con la misma gramática escrita dos veces. Dos parsers y dos
evaluadores es mucha superficie para que se separen sin que nadie se entere.

Esta implementación reduce esa superficie a la mitad:

> **PHP analiza. JavaScript solo evalúa.**

Una fórmula se analiza **una única vez**, en PHP, al publicar una versión de
plantilla. El AST resultante se guarda como JSON dentro de
`compiled_schema.fields[clave].ast`. El navegador no analiza nada: recibe el
mismo árbol que el servidor y solo lo recorre.

Consecuencias:

- Ya no puede haber discrepancias de **gramática**: precedencia, asociatividad,
  literales, errores de sintaxis… todo eso ocurre en un solo sitio.
- Lo único que hay que mantener en paralelo son las **semánticas de evaluación**
  (coerciones y funciones), que es lo que cubre `tests/fixtures/formula-cases.json`.
- Analizar es lo caro; se hace una vez al publicar y no en cada render.

Lo que se pierde: el navegador no puede validar sintaxis mientras escribes en el
constructor. Se resuelve validando en el servidor al salir del campo (Fase 3),
que es cuando el usuario espera el aviso de todos modos.

## Piezas

| Archivo | Qué hace |
|---|---|
| `Lexer.php` | Texto → tokens |
| `Parser.php` | Tokens → AST (arrays JSON-serializables) |
| `Ast.php` | Constructores de nodos + utilidades (referencias, profundidad) |
| `FunctionRegistry.php` | Lista blanca de funciones, con aridad y semántica |
| `Evaluator.php` | AST + contexto → valor |
| `Value.php` | Coerciones. **El archivo que hay que espejar con cuidado.** |
| `Formula.php` | Fachada: compilar, evaluar, extraer referencias |
| `Interpolator.php` | `1d20 + {@destreza.mod}` → `1d20 + 2` |
| `../../resources/js/formula/evaluate.js` | Evaluador espejo en el navegador |
| `../../resources/js/formula/sheet.js` | Componente Alpine que recalcula el editor al teclear |

El plan (§5.5) nombraba también `Compiler.php` y `DependencyGraph.php`. El
primero es `Formula::compile()` + `Formula::lint()`; el segundo vive en
`app/Domain/Schema/`, porque ordena campos de una plantilla, no fórmulas.

## Gramática

```
expr     := ternary
ternary  := or ( "?" expr ":" expr )?
or       := and ( ("||" | "or") and )*
and      := cmp ( ("&&" | "and") cmp )*
cmp      := add ( ("=="|"!="|"<"|"<="|">"|">="|"in"|"contains") add )*
add      := mul ( ("+"|"-") mul )*
mul      := unary ( ("*"|"/"|"%") unary )*
unary    := ("-"|"!"|"not")? postfix
postfix  := primary ( "[" ("*" | expr) "]" )? ( "." IDENT )*
primary  := NUMBER | STRING | "true" | "false" | "null"
          | "@" IDENT
          | IDENT "(" args? ")"
          | "[" args? "]"
          | "(" expr ")"
```

## `@self`

- `@self.nivel` es la hoja: lo mismo que `@nivel`.
- `@self` a secas es **el propio campo** dueño de la fórmula. Así una
  `mod_formula` como `floor((@self - 10) / 2)` se escribe una vez y se copia a
  todos los atributos. Se resuelve al compilar (`Ast::bindSelf`): el navegador
  recibe ya `@fuerza` y no necesita conocer la regla.

## Listas

Las operaciones aritméticas se aplican elemento a elemento si algún operando
es una lista: `[3, 2] * [1, 5]` → `[3, 10]`, `[3, 2] * 2` → `[6, 4]`. Es lo que
permite `sum(@inventario[*].peso * @inventario[*].cantidad)`. Con longitudes
distintas, la lista corta se completa con `null` (que vale 0).

## Reglas de coerción (idénticas en ambos motores)

- Los números son **un solo tipo**. Internamente se opera en coma flotante y un
  resultado entero se devuelve como entero (`3.0` → `3`), para que PHP y JS
  produzcan el mismo JSON.
- `null` vale `0` en aritmética y `""` en texto.
- `false` vale `0`; `true` vale `1`.
- Una cadena numérica se convierte en número en contexto aritmético; una no
  numérica vale `0`.
- Es **falsy**: `null`, `false`, `0`, `""`, `[]`. Todo lo demás es truthy.
  (Nota: `"0"` es *truthy*, a diferencia de PHP nativo — se ha unificado con la
  regla de JS por ser la menos sorprendente de las dos.)
- `==` compara sin tipo estricto usando estas mismas coerciones.
- **División por cero lanza error** en vez de devolver 0. Un cero silencioso en
  «CA = 10 + x/0» es un número equivocado en tu hoja en plena partida; un campo
  con «—» y un aviso es honesto. `SheetCalculator` lo captura por campo, así que
  una fórmula rota nunca tumba la hoja entera.

## Límites

Profundidad máxima de AST 64 y 512 nodos por fórmula, comprobados al compilar,
y 1024 tokens. Los paréntesis no crean nodos, así que el anidamiento se cuenta
además mientras se analiza: `((((…1…))))` con 80 niveles se rechaza aunque su
árbol tenga profundidad 1. Sin estos topes serían una bomba de expresión.
