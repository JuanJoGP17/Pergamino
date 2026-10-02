# Plan de desarrollo — Mesa de Pergamino v2 (Laravel + PostgreSQL)

> Migración de la SPA estática actual (HTML/JS + localStorage) a una aplicación
> Laravel con PostgreSQL, reorientada alrededor de un **constructor de hojas de
> personaje personalizadas** de nivel profesional.

**Fecha:** 16 de agosto de 2026
**Stack decidido:** Laravel 13 · PHP 8.3+ · PostgreSQL 16+ · Livewire 4 · Alpine.js 3 · Tailwind CSS 4
**Despliegue:** por decidir — ver **§15**. Se diseña para el mínimo común denominador (sin procesos persistentes)
**Alcance multijugador:** cuentas de usuario + mesas colaborativas con polling (sin WebSockets)

> **Nota de versiones.** Laravel 13 salió el 17 de marzo de 2026 y exige **PHP 8.3
> como mínimo** (soporta hasta 8.5); correcciones de fallos hasta Q3 2027 y
> parches de seguridad hasta marzo de 2028. Livewire 4 ya está publicado y trae
> componentes de archivo único, `wire:transition` e *islands* (re-render parcial),
> que encajan muy bien con el constructor de este proyecto.
>
> Comprueba que tu entorno tenga PHP 8.3+. Si te quedas en 8.2, usa
> **Laravel 12 + Livewire 3**: todo este plan es válido igual, solo cambian los
> números de versión.

---

## 1. Punto de partida y por qué cambiar

### Lo que ya existe y funciona

| Pieza | Estado | Destino en v2 |
|---|---|---|
| `js/dice.js` — parser de notación (`NdM`, `kh/kl`, ventaja) | Sólido | Se **porta a PHP** como servicio autoritativo + se conserva la versión JS para previsualización |
| `js/systems/dnd5e.js`, `pathfinder.js`, `generic.js` | Hardcodeados en JS | Se **convierten en plantillas de datos** (seeders), no en código |
| `js/systems/custom.js` — constructor de esquema | Prueba de concepto (6 tipos de campo, sin layout, sin fórmulas) | Se **sustituye** por el constructor completo. Es el núcleo de v2 |
| `js/storage.js` — localStorage | Fuente de verdad única | Se sustituye por PostgreSQL; se conserva un **importador** del JSON antiguo |
| Mesa con código `AURORA-7421` | Local, sin sincronización real | Se convierte en `campaigns` con miembros reales |

### El problema de fondo del diseño actual

Cada sistema de juego es **código JavaScript escrito a mano**. Añadir un sistema
significa programar. El sistema "custom" existe pero es de segunda categoría: sin
layout, sin fórmulas, sin apariencia propia, sin poder compartirse.

**La inversión que hace v2:** el modelo de primera clase es la **plantilla**
(*template*), y D&D 5e deja de ser código para convertirse en una plantilla más,
construida con las mismas herramientas que tiene cualquier usuario. Esto es la
prueba de fuego del diseño: si el constructor no puede expresar una hoja de D&D
5e completa, no es lo bastante potente.

---

## 2. Arquitectura general

```
┌─────────────────────────────────────────────────────────────┐
│  Navegador                                                   │
│  Blade + Livewire 4 (estado en servidor) + Alpine (local)   │
│  · Constructor: SortableJS para drag & drop                 │
│  · Evaluador de fórmulas JS (feedback instantáneo)          │
│  · Roller JS (solo previsualización, no autoritativo)       │
└────────────────────────┬────────────────────────────────────┘
                         │ HTTP / Livewire
┌────────────────────────┴────────────────────────────────────┐
│  Laravel 13                                                  │
│  ├─ Http/Livewire/    Sheet\Editor, Template\Builder, …     │
│  ├─ Domain/Schema/    Campos, compilador, validador         │
│  ├─ Domain/Formula/   Lexer, Parser, Evaluator, DepGraph    │
│  ├─ Domain/Dice/      Notation parser + roller autoritativo │
│  ├─ Domain/Theme/     Compilador de temas a CSS vars        │
│  ├─ Policies/         SheetPolicy, TemplatePolicy, …        │
│  └─ Services/         Export (JSON/PDF), Import, Fork       │
└────────────────────────┬────────────────────────────────────┘
                         │
┌────────────────────────┴────────────────────────────────────┐
│  PostgreSQL 16+  ·  UTF-8  ·  jsonb + GIN                    │
└─────────────────────────────────────────────────────────────┘
```

### Principios de diseño

1. **La plantilla es datos, no código.** Un sistema de juego = filas en la base
   de datos. Cero deploys para añadir un sistema.
2. **El servidor es la autoridad.** Fórmulas y tiradas se recalculan en PHP al
   guardar. El JS es solo para que el usuario vea el resultado sin esperar.
3. **Autoría normalizada, lectura compilada.** El constructor escribe en tablas
   normalizadas (fácil de editar por partes); al publicar se genera un
   `compiled_schema` JSON que el renderizador lee con **una sola consulta**.
4. **Las hojas se anclan a una versión de plantilla.** Editar una plantilla nunca
   rompe hojas ya creadas; el usuario decide cuándo actualizar.
5. **Nada de `eval`.** Ni en PHP ni en JS. Parser propio con lista blanca de
   funciones.

---

## 3. Modelo de datos (PostgreSQL)

### 3.1 Notas específicas de PostgreSQL

> **Cambio de motor (17 ago 2026).** El plan nació sobre MariaDB y se pasó a
> PostgreSQL al terminar la Fase 2, antes de tener datos reales. El coste fue
> casi nulo: el código no tenía ni una línea de SQL crudo ni consultas dentro de
> columnas JSON. Las razones, en §15.5.

- **`jsonb`, no `json`.** Es binario, se indexa con GIN y permite buscar dentro
  del documento. Los campos `repeater` de la Fase 4 y el catálogo de la Fase 7
  lo van a necesitar; `json` a secas guarda el texto tal cual y no se puede
  indexar por contenido.
- Para filtrar por un valor dentro de un `jsonb`, hay operadores nativos:
  ```sql
  -- todas las hojas de nivel 5
  SELECT * FROM sheets WHERE data @> '{"nivel": 5}';

  -- índice que hace eso rápido
  CREATE INDEX idx_sheets_data ON sheets USING GIN (data jsonb_path_ops);
  ```
  En Eloquent: `Sheet::whereJsonContains('data->nivel', 5)`.
- **UTF-8 por defecto.** No hay que declarar charset ni collation, ni preocuparse
  por longitudes de índice como en MySQL con `utf8mb4`.
- **El texto se compara distinguiendo mayúsculas.** MySQL, con su collation por
  defecto, no. Es la única diferencia que cambia el comportamiento de la
  aplicación, y hay que normalizar a mano: correos de usuario, códigos de mesa y
  cualquier búsqueda que el usuario escriba. Para buscar sin distinguir
  mayúsculas se usa `ILIKE` en vez de `LIKE`.
- **Sin enteros sin signo.** `unsignedInteger` se convierte en `integer`; el
  rango positivo lo garantiza la aplicación, no el motor.
- `lockForUpdate()` funciona igual que en MySQL.

### 3.2 Tablas

#### Usuarios y acceso

```
users
  id, name, email, email_verified_at, password, avatar_path,
  display_name, timezone, locale, settings jsonb, remember_token, timestamps
```

#### Plantillas (el corazón del sistema)

```
templates
  id, uuid, owner_id → users
  name, slug, tagline, description TEXT
  game_line             -- "D&D 5e", "Vampiro", "Casero"…
  settings jsonb        -- ajustes de mod(), prof() y tablas de lookup()
  cover_image_path, icon
  visibility            -- private | unlisted | public
  is_official BOOL      -- plantillas semilla del sistema
  forked_from_id → templates (nullable)
  current_version_id → template_versions (nullable)
  installs_count, likes_count
  timestamps, soft_deletes
  INDEX (visibility, installs_count)   -- listado del catálogo

template_versions
  id, template_id → templates
  version INT           -- 1, 2, 3… incremental
  label                 -- "1.2 – añadidos conjuros de nivel 9"
  changelog TEXT
  compiled_schema jsonb  -- snapshot completo: pestañas, secciones, campos, fórmulas, tema
  published_at, created_by → users, timestamps
  UNIQUE (template_id, version)
```

> **Por qué `compiled_schema`:** renderizar una hoja necesita todo el árbol de la
> plantilla. Con tablas normalizadas serían 4 JOINs y cientos de filas por cada
> carga. El snapshot compilado lo convierte en una lectura. Se regenera **solo**
> al publicar una versión.

#### Estructura de autoría (lo que edita el constructor)

```
template_tabs
  id, template_id, key, label, icon, position, is_default BOOL

template_sections
  id, template_tab_id, key, label, description
  position, columns TINYINT (1-4), collapsible BOOL, collapsed_default BOOL
  style jsonb            -- borde, fondo, acento propio de la sección
  visible_if VARCHAR    -- fórmula booleana opcional

template_fields
  id, template_section_id
  key VARCHAR(64)       -- identificador usado en fórmulas: "fuerza", "pv.actual"
  label, help_text
  type VARCHAR(32)      -- ver catálogo §4
  position, col_span TINYINT (1-12)
  config jsonb           -- opciones específicas del tipo
  default_value jsonb
  formula TEXT          -- si es calculado
  roll_expression TEXT  -- plantilla de tirada: "1d20 + {mod}"
  visible_if TEXT, readonly_if TEXT
  is_required BOOL, is_summary BOOL   -- is_summary → sale en la vista de mesa
  timestamps
  UNIQUE (template_id, key)   -- clave denormalizada para garantizar unicidad global
  INDEX (template_section_id, position)
```

> `template_fields.template_id` se duplica (columna denormalizada) únicamente
> para poder poner el índice `UNIQUE (template_id, key)`. Las claves deben ser
> únicas en toda la plantilla porque las fórmulas las referencian sin prefijo.

#### Hojas

```
sheets
  id, uuid, owner_id → users
  template_id → templates, template_version_id → template_versions
  name, portrait_path, banner_path
  data jsonb             -- { "fuerza": 16, "pv": {"actual":32,"max":40}, "ataques":[…] }
  computed jsonb         -- caché de campos calculados
  theme_override jsonb   -- ajustes de apariencia propios de esta hoja
  visibility            -- private | campaign | unlisted | public
  is_template_dirty BOOL -- true si la plantilla tiene versión más nueva
  timestamps, soft_deletes
  INDEX (owner_id, updated_at)

sheet_revisions
  id, sheet_id, user_id, data JSON, computed JSON
  summary VARCHAR       -- "PV 32 → 18"
  created_at
  -- retención: últimas 30 por hoja, poda con el scheduler

sheet_shares
  id, sheet_id, user_id (nullable), token (nullable, para enlace público)
  ability               -- view | edit
  expires_at, created_at
```

#### Mesas (campañas)

```
campaigns
  id, uuid, gm_id → users
  name, join_code CHAR(10) UNIQUE, description TEXT
  banner_path
  default_template_id → templates (nullable)
  settings jsonb         -- ¿los jugadores ven las hojas de otros? ¿tiradas ocultas al DJ?
  is_archived BOOL, timestamps

campaign_members
  id, campaign_id, user_id
  role                  -- gm | player | spectator
  nickname, joined_at
  UNIQUE (campaign_id, user_id)

campaign_sheet
  id, campaign_id, sheet_id
  share_level           -- full | summary | hidden
  position
  UNIQUE (campaign_id, sheet_id)

campaign_notes
  id, campaign_id, author_id, title, body TEXT (markdown)
  is_gm_only BOOL, timestamps
```

#### Tiradas

```
dice_rolls
  id, campaign_id (nullable), sheet_id (nullable), user_id
  label                 -- "Eldra · Salvación de Destreza"
  expression            -- "1d20+5"
  result jsonb           -- { dice:[{d:20,value:14,kept:true}], modifier:5, total:19, crit:false }
  mode                  -- normal | advantage | disadvantage
  is_private BOOL       -- tirada secreta del DJ
  created_at
  INDEX (campaign_id, created_at)   -- el log de mesa consulta por aquí
```

#### Recursos y catálogo

```
media
  id, user_id, disk, path, mime, size, width, height
  purpose               -- portrait | background | icon | template_cover
  timestamps

template_likes         (template_id, user_id, created_at)
template_installs      (template_id, user_id, created_at)   -- para el contador
```

### 3.3 Diagrama de relaciones

```
users ──┬── templates ──── template_versions
        │       └── template_tabs ── template_sections ── template_fields
        │
        ├── sheets ──┬── sheet_revisions
        │            ├── sheet_shares
        │            └── (template_version_id) → template_versions
        │
        ├── campaigns ──┬── campaign_members ── users
        │               ├── campaign_sheet ── sheets
        │               ├── campaign_notes
        │               └── dice_rolls
        └── media
```

---

## 4. Catálogo de tipos de campo

Esta es la herramienta principal. Cada tipo tiene: renderizador Blade, editor de
configuración en el constructor, validador, y serializador de valor.

### Básicos

| Tipo | `config` | Valor guardado |
|---|---|---|
| `heading` | `{ level, divider }` | — (decorativo) |
| `text` | `{ maxlength, placeholder, pattern }` | `string` |
| `textarea` | `{ rows, markdown: bool }` | `string` |
| `number` | `{ min, max, step, prefix, suffix }` | `number` |
| `select` | `{ options: [{value,label,color}], allow_empty }` | `string` |
| `multiselect` | `{ options, max_selections }` | `string[]` |
| `checkbox` | `{ label_inline }` | `bool` |
| `tags` | `{ suggestions[] }` | `string[]` |
| `color` | — | `#rrggbb` |
| `image` | `{ aspect, max_kb }` | `media_id` |

### Específicos de rol (la razón de ser del proyecto)

| Tipo | Para qué sirve | `config` | Valor |
|---|---|---|---|
| `attribute` | Puntuación + modificador derivado | `{ min, max, mod_formula, show_mod }` | `int` |
| `resource` | PV, maná, cordura — barra actual/máx/temporal | `{ show_temp, bar_color, allow_overflow }` | `{current, max, temp}` |
| `track` | Casillas de estrés/desgaste (FATE, Vampiro) | `{ boxes, shape: box\|dot\|pip, states: [] }` | `int[]` |
| `clock` | Reloj de progreso (Blades in the Dark) | `{ segments }` | `int` |
| `proficiency` | Habilidad con nivel de competencia | `{ levels: [{key,label,bonus}], base_field }` | `{level, misc}` |
| `dice_button` | Botón de tirada suelto | `{ expression, label, mode }` | — |
| `counter` | Contador con +/− (municiones, usos) | `{ min, max, reset_on }` | `int` |
| `repeater` | Tabla de filas tipadas (ataques, inventario, conjuros) | `{ columns: [{key,label,type,config,width}], min_rows, max_rows, sortable, row_formula }` | `object[]` |
| `derived_list` | Lista fija generada de una fuente (18 habilidades de 5e) | `{ items: [{key,label,attr}], row_template }` | `object` |
| `reference` | Enlace a otra hoja / entidad | `{ scope: campaign\|own }` | `sheet_uuid` |
| `computed` | Solo lectura, valor = fórmula | `{ format: int\|mod\|percent\|text }` | *(en `computed`)* |
| `currency` | Monedas con conversión | `{ denominations: [{key,label,rate}] }` | `object` |
| `progress` | Barra de experiencia con umbrales | `{ thresholds: [] }` | `int` |
| `portrait` | Retrato del personaje con marco | `{ frame, shape }` | `media_id` |

### Propiedades comunes a todos

- `key` — slug único, usado en fórmulas
- `col_span` — 1 a 12 dentro de la rejilla de la sección
- `visible_if` / `readonly_if` — fórmula booleana (campos condicionales)
- `roll_expression` — plantilla con interpolación: `1d20 + {@destreza.mod} + {@bono_competencia}`
- `is_summary` — si aparece en la tarjeta resumen de la vista de mesa
- `help_text` — tooltip

---

## 5. Motor de fórmulas

El diferenciador real frente a cualquier "form builder" genérico.

### 5.1 Gramática

```
expr     := ternary
ternary  := or ( "?" expr ":" expr )?
or       := and ( "||" and )*
and      := cmp ( "&&" cmp )*
cmp      := add ( ("=="|"!="|"<"|"<="|">"|">=") add )*
add      := mul ( ("+"|"-") mul )*
mul      := unary ( ("*"|"/"|"%") unary )*
unary    := ("-"|"!")? primary
primary  := NUMBER | STRING | BOOL
          | "@" IDENT ( "." IDENT )*          -- referencia a campo
          | IDENT "(" args ")"                -- llamada a función
          | "(" expr ")"
```

### 5.2 Referencias

```
@fuerza                  valor del campo "fuerza"
@fuerza.mod              propiedad derivada de un campo attribute
@pv.actual               subcampo de un resource
@ataques[*].bonus        columna completa de un repeater → array
@ataques[0].nombre       fila concreta
@self.nivel              alias explícito de la propia hoja
```

### 5.3 Funciones (lista blanca)

```
Matemáticas   floor ceil round abs min max clamp(v,lo,hi) pow sqrt sign
Agregación    sum(arr) avg(arr) count(arr) any(arr) all(arr)
Lógica        if(cond, a, b) coalesce(a, b, …) switch(v, c1,r1, c2,r2, …, default)
Texto         concat upper lower len
Rol           mod(score)             -- floor((score-10)/2), configurable por plantilla
              prof(level)            -- tabla de competencia de la plantilla
              lookup("tabla", clave) -- tablas de consulta definidas en la plantilla
              tier(v, [umbrales])
Dados         avg_of("2d6")          -- valor medio, para estimaciones (no tira)
```

### 5.4 Ejemplos reales

```php
// Modificador de atributo
mod(@fuerza)

// Clase de armadura 5e
10 + @destreza.mod + @armadura_bonus + if(@escudo, 2, 0)

// Bono de competencia por nivel
prof(@nivel)                          // → 2 + floor((nivel-1)/4)

// CD de conjuros
8 + prof(@nivel) + @carisma.mod

// Iniciativa con dote de Alerta
@destreza.mod + if(@dotes contains "Alerta", 5, 0)

// Carga total del inventario
sum(@inventario[*].peso * @inventario[*].cantidad)

// Salvación con competencia
@constitucion.mod + if(@salv_con.competente, prof(@nivel), 0)

// Campo condicional: mostrar espacios de conjuro solo si es lanzador
visible_if:  @clase in ["Mago","Clérigo","Bardo","Druida","Brujo","Hechicero"]
```

### 5.5 Implementación

**Lado PHP** (`app/Domain/Formula/`) — autoritativo:

```
Lexer.php        → tokens
Parser.php       → AST (Pratt / precedencia por escalada)
Compiler.php     → AST + validación de referencias → CompiledFormula
Evaluator.php    → CompiledFormula + contexto de hoja → valor
DependencyGraph.php → extrae @refs, orden topológico, detecta ciclos
FunctionRegistry.php → lista blanca; cada función declara aridad y tipos
```

**Lado JS** (`resources/js/formula/`) — espejo para feedback instantáneo:
misma gramática, mismas funciones. Un fichero de **casos de prueba compartido**
(`tests/fixtures/formula-cases.json`) se ejecuta contra ambos motores (Pest para
PHP, Vitest para JS) para garantizar que no divergen. Esto es innegociable: dos
motores con semánticas distintas es la fuente de bugs más cara del proyecto.

**Orden de recálculo:** al compilar una versión de plantilla se construye el DAG
de dependencias y se guarda el orden topológico en `compiled_schema.compute_order`.
Recalcular = recorrer esa lista una vez, O(n). Los ciclos se rechazan al
**publicar**, con mensaje señalando la cadena (`fuerza → ca → fuerza`).

**Caché:** el resultado se guarda en `sheets.computed`. Se invalida al guardar la
hoja o al migrar de versión de plantilla.

---

## 6. Constructor visual de hojas

### 6.1 Pantalla del constructor (`/templates/{uuid}/builder`)

```
┌────────────┬───────────────────────────────────┬─────────────────────┐
│ PALETA     │ LIENZO                            │ INSPECTOR           │
│            │                                   │                     │
│ Básicos    │ [Identidad][Combate][Magia]  +    │ Campo: fuerza       │
│  Texto     │ ┌────────────────────────────┐    │ ──────────────────  │
│  Número    │ │ ▸ Atributos    2 columnas  │    │ Clave    fuerza     │
│  Lista     │ │  ┌────────┐ ┌────────┐     │    │ Tipo     Atributo   │
│  Selección │ │  │FUE  16 │ │DES  14 │  ⠿  │    │ Ancho    ▓▓▓░░░░    │
│            │ │  │   +3   │ │   +2   │     │    │ Fórmula  mod(@fue)  │
│ Rol        │ │  └────────┘ └────────┘     │    │ Tirada   1d20+{mod} │
│  Atributo  │ │ ▸ Salvaciones              │    │ Visible  siempre    │
│  Recurso   │ └────────────────────────────┘    │ ☑ En resumen        │
│  Marcas    │                                   │                     │
│  Reloj     │ [+ Sección]      [+ Pestaña]      │ [Eliminar]          │
│  Repetidor │                                   │                     │
├────────────┴───────────────────────────────────┴─────────────────────┤
│ ⚠ 2 avisos: campo "destreza2" sin usar · fórmula rota en "ca"        │
└──────────────────────────────────────────────────────────────────────┘
```

**Componentes Livewire:**

```
Template\Builder            contenedor, estado del árbol
Template\Builder\Palette    catálogo arrastrable
Template\Builder\Canvas     pestañas → secciones → rejilla
Template\Builder\Inspector  configuración del elemento seleccionado
Template\Builder\Validator  panel de avisos (poll cada 2s o al cambiar)
Template\ThemeEditor        apariencia
Template\PreviewPane        hoja renderizada con datos de ejemplo
```

**Drag & drop:** SortableJS envuelto en una directiva Alpine `x-sortable`, que
emite `$wire.moveField(fieldId, sectionId, position)`. Reordenar dentro de una
sección es optimista en cliente y se confirma en servidor. Los tres niveles
(pestaña, sección, campo) son ordenables.

### 6.2 Herramientas de productividad del constructor

1. **Vista previa en vivo** — panel lateral con la hoja renderizada y datos de
   ejemplo generados. Alternable entre escritorio / móvil / impresión.
2. **Duplicar campo / sección / pestaña** con renombrado automático de claves.
3. **Añadir en bloque** — pegar una lista de nombres y crear N campos del mismo
   tipo de golpe (las 18 habilidades de 5e en una operación).
4. **Bloques prefabricados** — insertar de un clic: "6 atributos estilo d20",
   "bloque de PV/CA/iniciativa", "tabla de conjuros por nivel", "inventario con
   peso", "reloj de progreso". Es lo que hace usable el constructor para alguien
   que no quiere empezar de cero.
5. **Tablas de consulta** por plantilla (`lookup()`): tabla de competencia, dados
   de golpe por clase, umbrales de experiencia. Editables en una pestaña propia.
6. **Panel de validación** en tiempo real: claves duplicadas, fórmulas con
   referencias inexistentes, ciclos, campos huérfanos, campos sin etiqueta.
7. **Importar JSON** de una plantilla exportada o del formato antiguo.
8. **Historial de versiones** con diff legible entre dos versiones publicadas.
9. **Modo borrador vs publicado** — se edita el borrador; publicar crea una
   `template_version` inmutable. Las hojas existentes siguen en su versión.
10. **Migración asistida** — al publicar una versión con cambios incompatibles
    (campo renombrado/eliminado), se define un mapa de migración
    `{ "fuerza_antigua": "fuerza" }` que se aplica al actualizar cada hoja.

### 6.3 Editor de apariencia

El tema se guarda como JSON y se compila a **variables CSS** inyectadas en un
`<style>` con ámbito `[data-sheet="uuid"]`. Nunca se acepta CSS crudo del
usuario: solo un conjunto de propiedades permitidas y valores validados.

```json
{
  "preset": "pergamino",
  "colors": {
    "bg": "#f4ecd8", "surface": "#fffdf5", "ink": "#2b2118",
    "accent": "#8b2e1f", "muted": "#7a6a55", "border": "#c9b48a"
  },
  "typography": {
    "heading": "Cinzel", "body": "EB Garamond",
    "scale": 1.0, "heading_transform": "uppercase"
  },
  "surface": {
    "texture": "parchment-2",
    "corner_radius": 4,
    "border_style": "ornate",
    "shadow": "soft"
  },
  "layout": { "density": "comfortable", "max_width": 1100 },
  "custom_background": { "media_id": 42, "opacity": 0.15, "repeat": "tile" }
}
```

**Presets incluidos:** Pergamino, Grimorio oscuro, Cyberpunk neón, Minimal
papel, Sci-fi terminal, Cómic, Máquina de escribir.

**Tipografías:** conjunto curado **auto-alojado** (no Google Fonts por CDN —
mejor rendimiento y menos dependencias externas): Cinzel, EB Garamond, IM Fell,
Inter, JetBrains Mono, Bebas Neue, Special Elite.

**Alcance del tema:** plantilla → mesa → hoja, en cascada. Una hoja puede
sobrescribir el acento y el retrato sin romper el diseño de la plantilla.

**Modo claro / oscuro:** cada preset define ambas paletas; se respeta
`prefers-color-scheme` con opción de forzar.

### 6.4 Exportación e impresión

- **PDF** con **dompdf** (PHP puro, funciona en cualquier hosting; nada de headless Chrome).
  Hoja de estilos `print.css` dedicada, paginación por pestañas, marca de agua
  opcional. Limitación aceptada: dompdf no soporta CSS Grid → el renderizador de
  impresión usa una variante con tablas.
- **PNG/JPG** de la ficha: fuera de alcance sin VPS (requiere navegador
  headless). Alternativa: botón "imprimir a PDF" del navegador.
- **JSON** de hoja y de plantilla (formato versionado, el mismo que importa).

---

## 7. Motor de dados (PHP autoritativo)

`app/Domain/Dice/` — port del `dice.js` actual, ampliado.

```
Notación soportada
  NdM              4d6
  +N / -N          1d20+5
  khN / klN        4d6kh3     quedarse con los N más altos/bajos
  dhN / dlN        4d6dl1     descartar
  !  / !N          3d6!       explosivos (relanza el máximo, o >= N)
  rN / roN         2d6r1      relanzar unos (r = repetido, ro = una vez)
  >=N              5d10>=7    contar éxitos (Storyteller)
  dF               4dF        dados Fudge/FATE
  cs / cf          umbral de crítico / pifia
  { } aritmética   (2d6+3)*2
  ventaja/desv.    azúcar sobre 2d20kh1 / 2d20kl1
```

**Salida estructurada** para poder renderizar bonito:

```json
{
  "expression": "4d6kh3+2",
  "dice": [
    {"sides":6,"value":5,"kept":true},
    {"sides":6,"value":3,"kept":true},
    {"sides":6,"value":6,"kept":true,"exploded":false},
    {"sides":6,"value":1,"kept":false}
  ],
  "modifier": 2, "total": 16,
  "crit": false, "fumble": false, "successes": null
}
```

- Aleatoriedad con `random_int()` (CSPRNG), nunca `rand()`.
- Las tiradas desde una hoja vinculada a mesa se persisten en `dice_rolls`.
- **Tiradas del DJ ocultas** (`is_private`): visibles solo para él.
- Rate limit: 30 tiradas/min por usuario.
- Interpolación de la plantilla de tirada del campo antes de parsear:
  `1d20 + {@destreza.mod} + {@bono_competencia}` → `1d20+2+3`.

---

## 8. Mesas colaborativas (sin WebSockets)

Se asume el escenario más restrictivo (hosting sin procesos persistentes) → nada
de Reverb. **Solución: polling con Livewire**, suficiente para el caso de uso, y
que además funciona en *todas* las opciones de despliegue de §15. Si acabas en un
VPS, ver §15.4 antes de cambiar nada.

- Vista de mesa: `wire:poll.4s` sobre el log de tiradas y las tarjetas resumen.
  Con Livewire 4 conviene envolver el log en un **island**, para que el poll
  re-renderice solo esa porción y no la vista de mesa entera.
- Endpoint ligero: consulta `dice_rolls WHERE campaign_id = ? AND id > ?`
  (índice cubierto) + `updated_at` de las hojas. Coste por poll: 2 consultas.
- Si más adelante migras a VPS, se sustituye el poll por Reverb sin tocar el
  resto: los mismos eventos ya se emiten (`RollCreated`, `SheetUpdated`).

**Funciones de la mesa:**

- Código de invitación (`join_code`) + enlace directo
- Roles: DJ / jugador / espectador
- Tarjetas resumen de cada personaje construidas con los campos marcados
  `is_summary` (PV, CA, iniciativa, percepción pasiva…)
- Nivel de compartición por hoja: completa / solo resumen / oculta
- El DJ puede editar cualquier hoja de la mesa (configurable)
- Log de tiradas compartido, con filtro por jugador
- Notas de mesa (markdown), con notas privadas del DJ
- Iniciativa: lista ordenable con turno actual y contador de rondas
- Plantilla sugerida: al unirse, crear hoja con un clic

---

## 9. Rutas y pantallas

| Ruta | Pantalla |
|---|---|
| `/` | Panel: mis hojas, mis mesas, plantillas recientes |
| `/register` `/login` | Starter kit de Livewire |
| `/sheets` | Mis hojas — rejilla con retrato, filtros, búsqueda |
| `/sheets/create` | Elegir plantilla (mías / catálogo / recientes) |
| `/sheets/{uuid}` | **Editor de hoja** — pestañas, autoguardado, dados |
| `/sheets/{uuid}/print` | Vista de impresión / descarga PDF |
| `/sheets/{uuid}/history` | Revisiones, restaurar |
| `/templates` | Mis plantillas |
| `/templates/create` | Nueva: en blanco / desde bloques / clonar |
| `/templates/{uuid}/builder` | **Constructor** |
| `/templates/{uuid}/theme` | Editor de apariencia |
| `/templates/{uuid}/versions` | Versiones y publicación |
| `/catalog` | Catálogo público de plantillas, filtros por línea de juego |
| `/catalog/{slug}` | Ficha de plantilla + previsualización + "usar/clonar" |
| `/campaigns` | Mis mesas |
| `/campaigns/{uuid}` | **Vista de mesa** |
| `/campaigns/join/{code}` | Unirse |
| `/import` | Importar JSON (incluye formato antiguo) |
| `/settings` | Perfil, tema global, preferencias |

---

## 10. Seguridad

- **Policies** para `Sheet`, `Template`, `Campaign`, `CampaignNote`. Nada de
  comprobaciones de permisos dispersas en los componentes.
- **Sin `eval`** en ninguna parte. El evaluador de fórmulas trabaja sobre AST con
  registro de funciones en lista blanca; profundidad de AST limitada (p. ej. 64)
  y número de nodos limitado para evitar bombas de expresión.
- **Tema:** propiedades y valores en lista blanca; los colores se validan contra
  `/^#[0-9a-f]{6}$/i`, las fuentes contra el catálogo. Nunca CSS crudo.
- **Markdown:** renderizado con `league/commonmark` en modo seguro
  (`html_input: strip`, `allow_unsafe_links: false`).
- **Subidas:** validación MIME real (no solo extensión), reescritura de la imagen
  con Intervention Image (elimina EXIF y payloads), límite 4 MB, nombres
  aleatorios, almacenamiento fuera de `public/` con ruta de servicio firmada.
- **Rate limits:** tiradas 30/min, guardado de hoja 120/min, registro 5/h por IP.
- **Enlaces públicos** de hoja con token aleatorio de 32 bytes y expiración.
- Reglas de validación explícitas por tipo de campo al guardar `data` (nunca
  confiar en el JSON que llega del cliente).

---

## 11. Rendimiento

- **Renderizar una hoja = 2 consultas:** la hoja + su `template_version`
  (con `compiled_schema`). El árbol normalizado solo se toca en el constructor.
- Cachear `compiled_schema` en el caché de aplicación (driver `file` basta)
  con clave `tpl_v:{id}`; es inmutable, no hace falta invalidación.
- `sheets.computed` evita recalcular fórmulas en cada render.
- Autoguardado con *debounce* de 800 ms + guardado por diferencias (solo los
  campos que cambiaron), no la hoja entera.
- Vistas de listado: `select` explícito sin la columna `data`.
- Poda de `sheet_revisions` (>30 por hoja) y `dice_rolls` (>90 días) desde el
  scheduler.

---

## 12. Pruebas

- **Pest** para todo el backend.
- **Motor de fórmulas** — la batería más importante. `formula-cases.json`
  compartido entre PHP y JS: ~120 casos cubriendo cada función, precedencia,
  arrays de repeaters, división por cero, referencias nulas, ciclos.
- **Motor de dados** — con generador de aleatoriedad inyectable y semilla fija;
  cada modificador de notación con su caso.
- **Compilador de esquema** — plantilla normalizada → `compiled_schema` correcto;
  orden topológico; detección de ciclos.
- **Migración de versión** — hoja en v1 + mapa → hoja en v2 sin pérdida de datos.
- **Policies** — matriz de acceso: dueño / miembro de mesa / DJ / extraño /
  enlace público, contra cada acción.
- **Importador** — JSON antiguo (`mp:sheets` + `mp:mesas`) → entidades nuevas.
- **Feature tests** de los flujos: crear plantilla → publicar → crear hoja →
  vincular a mesa → tirar dados.

---

## 13. Migración de los datos actuales

Pantalla `/import` que acepta el `backup.json` que genera `exportAll()` hoy.

| Origen | Destino |
|---|---|
| `mp:sheets[].systemId = 'dnd5e'` | Plantilla oficial semilla "D&D 5e" |
| `…= 'pathfinder'` | Plantilla oficial "Pathfinder 1e" |
| `…= 'generic'` | Plantilla oficial "Genérico" |
| `…= 'custom'` + `data._schema` | **Se crea una plantilla nueva** por hoja custom, traduciendo cada campo (`header`→sección, `attr`→`attribute`, `list`→`repeater`, etc.) |
| `data` (resto de claves) | `sheets.data`, con mapa de claves por sistema |
| `mp:mesas[]` | `campaigns` con el usuario importador como DJ |
| `mesa.sheetIds` | `campaign_sheet` |

El mapa de claves de cada sistema antiguo (`js/systems/dnd5e.js` → claves de la
plantilla semilla) se escribe una vez en
`app/Domain/Import/LegacyMaps/Dnd5e.php`. El importador informa de los campos que
no supo mapear en vez de descartarlos en silencio: los deja en
`data._unmapped` visibles en un aviso de la hoja.

---

## 14. Fases de desarrollo

Estimaciones para una persona trabajando de forma intermitente. Cada fase deja la
aplicación **funcionando y desplegable**.

### Fase 0 — Cimientos · ~1 semana
- `laravel new` con el *starter kit* de Livewire, Tailwind, Pest
- Conexión PostgreSQL, extensión `pdo_pgsql`, convenciones (`jsonb`)
- Layout base, navegación, tema global claro/oscuro
- CI mínimo (GitHub Actions: Pest + Pint)
- **Entregable:** registro, login y panel vacío funcionando **en local**

### Fase 1 — Modelo y motor de esquema · ~2 semanas
- Todas las migraciones de §3
- Modelos Eloquent, factories, relaciones, policies esqueleto
- `SchemaCompiler`: árbol normalizado → `compiled_schema`
- Renderizador de hoja (Blade) para los 8 tipos de campo básicos
- **Entregable:** una plantilla creada a mano por seeder se renderiza como hoja
  editable y guarda valores

### Fase 2 — Motor de fórmulas · ~2 semanas
- Lexer, Parser, Evaluator, FunctionRegistry, DependencyGraph en PHP
- Espejo en JS + `formula-cases.json` compartido
- Tipo de campo `computed`, `visible_if`, `readonly_if`
- Tipo `attribute` con `mod_formula`, tablas `lookup()`
- **Entregable:** hoja con CA, modificadores y competencia calculándose solos,
  al instante en cliente y verificados en servidor

### Fase 3 — Constructor visual · ~3 semanas
- Pantalla builder: paleta, lienzo, inspector
- Drag & drop en los tres niveles (pestaña/sección/campo)
- Rejilla de 12 columnas, `col_span`
- Configuración por tipo en el inspector
- Panel de validación
- Borrador → publicar → `template_version`
- Vista previa en vivo
- **Entregable:** un usuario crea un sistema de juego completo sin escribir código

### Fase 4 — Tipos de campo avanzados · ~2 semanas
- `resource`, `track`, `clock`, `proficiency`, `counter`, `currency`, `progress`
- `repeater` con columnas tipadas y `row_formula`
- `derived_list`
- `portrait` + subida de imágenes (Intervention, validación, servicio de rutas)
- Bloques prefabricados de §6.2
- **Entregable:** el catálogo de campos cubre D&D 5e, FATE, Vampiro y Blades

### Fase 5 — Apariencia y exportación · ~1.5 semanas
- Editor de temas + 7 presets + cascada plantilla→mesa→hoja
- Compilador de tema a variables CSS con lista blanca
- Fuentes auto-alojadas
- `print.css` + exportación PDF con dompdf
- Export/import JSON de plantillas y hojas
- **Entregable:** las hojas se ven distintas según el sistema, e imprimibles

### Fase 6 — Dados y mesas · ~2 semanas
- Port del roller a PHP con toda la notación de §7
- Botones de tirada en los campos, bandeja de dados global, historial
- Mesas: crear, unirse por código, roles, permisos por hoja
- Vista de mesa con tarjetas resumen, log con polling, iniciativa, notas
- **Entregable:** una partida real se puede jugar en la aplicación

### Fase 7 — Catálogo y compartición · ~1.5 semanas
- Plantillas públicas, catálogo con filtros y búsqueda
- Clonar/forkear plantilla (con `forked_from_id`)
- Likes, contador de instalaciones
- Compartir hoja por enlace, permisos de edición
- Semillas oficiales: D&D 5e, Pathfinder 1e, Genérico, FATE Condensado,
  Blades in the Dark — **construidas con el constructor, no en código**
- **Entregable:** el catálogo tiene contenido y la comunidad puede aportar

### Fase 8 — Migración, pulido y despliegue · ~1.5 semanas
- Importador del formato antiguo (§13)
- Revisiones e historial de hojas con restauración
- Atajos de teclado, accesibilidad (foco, ARIA, contraste AA)
- Responsive real en móvil (el editor de hoja es el caso difícil)
- Scheduler: poda de revisiones y tiradas
- Despliegue según la opción elegida en §15 + script de actualización
- **Entregable:** v2.0

**Total estimado: ~17 semanas** de trabajo efectivo. Las fases 0-3 son el camino
crítico; a partir de la 4 el orden es negociable.

---

## 15. Despliegue — opciones y comparativa

> **Actualización (16 ago 2026):** el cPanel de pago queda descartado por
> presupuesto. Esta sección compara las alternativas reales y recomienda un
> camino.

### 15.0 Lo primero: todavía no necesitas desplegar nada

Las fases 0 a 3 —cimientos, modelo de datos, motor de fórmulas y constructor
visual— son unas **8 semanas de trabajo íntegramente en local**. Ese es el 60 %
del proyecto y el 100 % de la parte difícil.

Entorno de desarrollo local, gratis y en Windows:

- **Laravel Herd** (gratis) — PHP, nginx y `herd` en un clic. La opción cómoda.
- **Laragon** (gratis) — PostgreSQL se añade desde *Herramientas → Quick add*.
  Recomendado.
- **XAMPP** — funciona, pero es el que peor envejece.

No quemes los 15 días de prueba del cPanel ahora: no tendrás nada que subir
hasta dentro de dos meses, y para entonces la prueba habrá caducado.

---

### 15.1 Comparativa de opciones

| Opción | Coste real | Base de datos | Qué te da | Qué te cuesta |
|---|---|---|---|---|
| **A. Oracle Cloud Always Free** | **0 €, permanente** | PostgreSQL en la propia VM | VPS ARM completo: 2 OCPU + 12 GB RAM, 200 GB disco, 10 TB tráfico/mes, SSH, cron, colas, Redis, **WebSockets** | Montarlo tú (un fin de semana), tarjeta para verificar, riesgo de reclamación por inactividad |
| **B. Laravel Cloud (Starter)** | **5 $/mes**, primer mes gratis | Postgres serverless incluido | Deploy desde git, cero administración, *scale-to-zero*, certificados, copias | 5 $/mes fijos; menos control |
| **C. Render (free) + Neon o Supabase (free)** | **0 €** | Neon: 0,5 GB por proyecto, *scale-to-zero*; Supabase: 500 MB | Deploy desde git gratis, 750 h/mes | **Se duerme a los 15 min** y tarda ~1 min en despertar; requiere Docker (Render no tiene runtime PHP); sin cron nativo |
| **D. cPanel compartido de pago** | 2–5 €/mes según promoción | Casi siempre **solo MySQL** | Familiar, simple | Al elegir PostgreSQL esta opción se complica: pocos cPanel lo ofrecen. Sin SSH garantizado, sin WebSockets |
| ~~E. InfinityFree / 000webhost~~ | 0 € | MySQL 50 MB | — | **Descartado**, ver §15.3 |

---

### 15.2 Análisis de las tres opciones vivas

#### A. Oracle Cloud Always Free — *la recomendada*

Es la única que es **gratis de verdad y para siempre**, y además es un VPS
completo, no un hosting recortado.

**Lo que incluye (Always Free, no expira al acabar la prueba de 30 días):**

- 2 OCPU ARM (Ampere A1) + 12 GB RAM — *recortado en 2026 desde los 4 OCPU /
  24 GB históricos; sigue siendo generoso*
- 2 micro-instancias AMD adicionales (1/8 OCPU, 1 GB RAM) de regalo
- 200 GB de almacenamiento en bloque
- **10 TB de tráfico saliente al mes**
- 1 balanceador de carga flexible
- 1 MySQL HeatWave con 50 GB — no aplica al haber elegido PostgreSQL; en la VM
  se instala `postgresql` y listo, sin tocar la cuota Always Free
- 2 VCN, 20 GB de object storage

**Lo que gana el proyecto:** desaparecen de golpe todas las restricciones que
condicionaron este plan. Tendrías SSH, cron real, `supervisor` para las colas,
Redis, Node para compilar en el servidor, y **WebSockets con Laravel Reverb**.

**Los dos inconvenientes honestos:**

1. **Reclamación por inactividad.** Oracle marca una instancia como *idle* si
   durante **7 días seguidos** el percentil 95 de CPU, red y memoria está por
   debajo del 20 %, y puede reclamarla. Para un proyecto con poco tráfico esto
   es un riesgo real. Mitigación: copias de seguridad automáticas fuera de
   Oracle + un cron ligero que genere algo de actividad. No lo ignores.
2. **Disponibilidad de capacidad ARM.** En algunas regiones el shape Ampere A1
   sale "out of capacity" durante días. Se resuelve reintentando (hay scripts
   comunitarios para ello) o eligiendo bien la región de origen — que **no se
   puede cambiar después**, así que piénsalo al registrarte.

También pide tarjeta para verificar la identidad. No cobra si te quedas en los
recursos Always Free, pero es un requisito.

**Trabajo de montaje:** Ubuntu 22/24 ARM + nginx + PHP-FPM 8.3 + PostgreSQL +
Certbot + supervisor. Un fin de semana la primera vez. Existen scripts y guías
abundantes.

#### B. Laravel Cloud — *si prefieres pagar 5 $ y no ser sysadmin*

Plan Starter: **5 $/mes, primer mes gratis**, con 5 $ de créditos de uso
incluidos. Trae Postgres serverless, despliegue desde git, certificados y
*scale-to-zero* (la app duerme cuando nadie la usa y despierta en menos de
500 ms — sin la penalización de un minuto de Render).

Referencia de coste de uso que publica Laravel: un proyecto de hobby despierto
~4 h al mes ≈ 0,39 $ de consumo, holgadamente cubierto por los créditos. Es
decir, el coste real es la cuota de 5 $.

Es la opción de *comprar tiempo*: te ahorra el fin de semana de montaje y todo
el mantenimiento posterior. Si 5 $/mes entra en el presupuesto, es defendible.

#### C. Render + Neon/Supabase — *gratis, pero solo sirve para enseñar el proyecto*

Render da 750 horas de instancia al mes sin tarjeta, pero el servicio gratuito
**se apaga tras 15 minutos de inactividad y tarda cerca de un minuto en
arrancar**. Para una web de hojas de personaje —donde alguien entra a mirar su
ficha 30 segundos— es una experiencia mala.

La base de datos, en cambio, ya no es un problema: el PostgreSQL gratuito de
Render caduca a los 30 días, pero **Neon** (0,5 GB por proyecto, hasta 100
proyectos, con *scale-to-zero* que reanuda sin espera) y **Supabase** (500 MB,
se pausa tras una semana sin uso) no caducan. Cualquiera de los dos vale.

Render no tiene runtime PHP: hay que empaquetar la app en un Dockerfile.

**Veredicto:** válido como *staging* público o para enseñarle el proyecto a
alguien. No como producción.

---

### 15.3 Descartadas, y por qué

- **InfinityFree, 000webhost y similares.** Sobre el papel dan PHP 8.3 y MySQL
  gratis (PostgreSQL ni eso). En la práctica: **sin SSH, sin cron**, bases de datos limitadas a
  50 MB, tope de 30 000 archivos (inodes), tope de 30 000 peticiones diarias
  contando imágenes y CSS, `mail()` desactivado y SMTP saliente bloqueado. Sin
  cron no hay planificador de Laravel, y sin planificador no hay colas ni poda
  de revisiones. **No sirve para esta aplicación.**
- **Fly.io.** Ya no ofrece nivel gratuito a cuentas nuevas.
- **Railway.** 5 $ de crédito el primer mes y 1 $/mes después; los servicios se
  pausan al agotarlo. No da para una app siempre disponible.
- **Vercel, Netlify, Cloudflare Workers.** No ejecutan PHP.

---

### 15.4 Consecuencia para el diseño: no cambies el plan todavía

Si acabas en Oracle (A) o Laravel Cloud (B), técnicamente podrías usar Reverb y
tener mesas en tiempo real de verdad. **Aun así, mantén el diseño con polling.**

El motivo: una arquitectura de *polling* funciona en las cuatro opciones; una de
WebSockets solo funciona en dos. Construye para el mínimo común denominador y
sube el listón en la Fase 6, cuando ya sepas dónde vas a desplegar. El plan ya
emite los eventos `RollCreated` y `SheetUpdated` precisamente para que ese cambio
sea sustituir el emisor, no reescribir la vista de mesa.

Lo mismo aplica a la exportación: dompdf funciona en todas partes; un navegador
headless, solo en un VPS.

---

### 15.5 Por qué PostgreSQL (decidido el 17 ago 2026)

El plan nació sobre MariaDB. Al terminar la Fase 2 se cambió a PostgreSQL,
**antes de tener datos reales y antes de la Fase 4**, que es justo donde el
cambio habría empezado a doler.

**El coste fue casi nulo**, y eso se pudo comprobar auditando el código: cero
líneas de SQL crudo, cero consultas dentro de columnas JSON, solo Eloquent y
migraciones estándar. Todo el acoplamiento al motor cabía en dos sitios.

Dos razones para hacerlo:

1. **JSON.** `jsonb` es binario y se indexa con GIN. Los campos `repeater` de la
   Fase 4 y el catálogo público de la Fase 7 van a querer buscar *dentro* del
   documento, y ahí es donde los dos motores dejan de parecerse: columnas
   generadas en MySQL frente a operadores nativos e índices GIN en PostgreSQL.
   Es código distinto, no un ajuste de configuración.
2. **Alojamiento gratuito.** Los niveles gratuitos de MySQL/MariaDB gestionado
   prácticamente han desaparecido —PlanetScale eliminó el suyo en 2024 y nadie
   lo reemplazó—, mientras que los de PostgreSQL abundan: Supabase, Neon,
   Render. Siendo el presupuesto la restricción principal (§15), elegir el motor
   con más opciones gratuitas quita un problema de encima antes de que llegue.

**Cuándo habría sido caro cambiar.** El coste de esta decisión no crece poco a
poco: salta en dos momentos concretos. Cuando haya hojas de gente de verdad,
deja de ser un cambio de código y pasa a ser una migración de datos. Y en la
Fase 4, cuando el modelo empiece a apoyarse en las capacidades JSON del motor.
Hacerlo entre la Fase 2 y la 3 fue, con diferencia, el momento más barato.

**Lo que hubo que tocar:** `json` → `jsonb` en 13 columnas, quitar 6 `->after()`
(exclusivos de MySQL) y —la única diferencia que cambia el comportamiento de la
aplicación— **normalizar mayúsculas**, porque PostgreSQL compara texto
distinguiéndolas y MySQL no. Sin eso, «Juan@Gmail.com» y «juan@gmail.com» serían
dos cuentas distintas. Está fijado con tests.

**Sobre SQLite.** Sigue siendo tentador por lo simple que hace el despliegue —la
base de datos es un archivo—, y para 20-50 usuarios rendiría de sobra. Pero deja
de estar a un paso: es el único de los tres motores que **no sabe añadir una
clave foránea a una tabla que ya existe**, y el modelo tiene una referencia
circular (`templates.current_version_id` ↔ `template_versions`) que la necesita.
Son unas 15 líneas de arreglo, no un rediseño, pero ya no es gratis. Y no vale
en plataformas de disco efímero como el nivel gratuito de Render.

---

### 15.6 Guía de montaje en Oracle Cloud (opción A)

Resumen del camino, para cuando llegue la Fase 8:

1. **Registro.** Elige bien la región de origen (la más cercana con capacidad
   ARM disponible); **no se puede cambiar luego**.
2. **Instancia.** Ampere A1, Ubuntu 24.04 LTS, 2 OCPU / 12 GB, 100 GB de boot
   volume. Guarda la clave SSH.
3. **Red.** Abre los puertos 80 y 443 en la Security List de la VCN **y**
   en `iptables` de la propia instancia — Oracle trae reglas locales que
   sorprenden a todo el mundo la primera vez.
4. **Pila.** `nginx`, `php8.3-fpm` con las extensiones de §15.7, `postgresql`,
   `composer`, `nodejs`, `certbot`, `supervisor`, `redis-server`.
5. **Despliegue.** Clona el repositorio, `composer install --no-dev -o`,
   `npm ci && npm run build`, `php artisan migrate --force`, cachés de
   configuración/rutas/vistas.
6. **Procesos.** `supervisor` para `queue:work`; una línea de cron para
   `schedule:run`. Aquí sí puedes usar `queue:work` permanente, sin el apaño de
   `--stop-when-empty`.
7. **Dominio.** Un `.com` cuesta ~10 €/año; alternativas gratuitas: un subdominio
   de DuckDNS o similar. Certificado con Certbot, gratis.
8. **Copias de seguridad.** `pg_dump` + `tar` de `storage/app` a object storage
   de Oracle (20 GB gratis) **y** a un segundo destino fuera de Oracle —
   por la política de reclamación. `spatie/laravel-backup` lo automatiza.
9. **Anti-reclamación.** Un cron que cada hora haga algo de trabajo real
   (revalidar cachés, generar métricas) para no quedar bajo el 20 % los 7 días.

### 15.7 Extensiones PHP requeridas (cualquier opción)

```
bcmath ctype curl dom fileinfo json mbstring openssl
pcre pdo pdo_pgsql tokenizer xml gd zip
```

`gd` es para Intervention Image (retratos). `zip` para importar/exportar
paquetes de plantillas.

---

### 15.8 Si aun así quieres cPanel más adelante

Todo lo escrito en la versión anterior de esta sección sigue siendo válido:
apuntar `public_html` al `public/` de Laravel modificando `index.php`, compilar
los assets en local porque no hay Node, driver de colas `database` con
`queue:work --stop-when-empty --max-time=50` lanzado desde el cron del
planificador, sesiones y caché en `file`, y comprobar con
`curl https://tudominio/.env` que devuelve 404. Búscalo en el historial de git de
este documento si llega el caso.

---

## 16. Riesgos y decisiones abiertas

| Riesgo | Mitigación |
|---|---|
| **Los dos motores de fórmulas divergen** | Batería de casos compartida, ejecutada en CI contra PHP y JS. Es la única defensa real. |
| **El constructor se vuelve demasiado complejo de usar** | Bloques prefabricados + clonar plantillas existentes. Nadie debería empezar en blanco. |
| **El polling satura el hosting compartido** | Intervalo 4 s, consultas con índice cubierto, pausar el poll con la pestaña oculta (`document.hidden`). |
| **Migrar hojas al cambiar plantilla pierde datos** | Versionado inmutable + mapa de migración explícito + `data._unmapped` en lugar de descarte silencioso. |
| **`compiled_schema` crece demasiado** | Una hoja de 5e completa ≈ 60-80 KB de JSON. Aceptable. Si superara 1 MB, comprimir con gzip en la columna. |
| **Contenido con derechos en el catálogo público** | Las plantillas definen *estructura*, no reglas ni texto de manuales. Aviso en la publicación y sistema de reportes. |

**Decisiones pendientes de tu criterio:**

1. ¿Las plantillas oficiales las mantienes tú o se abren desde el principio a la
   comunidad? (afecta a moderación)
2. ¿Idioma único (español) o i18n desde el inicio? Meterlo después es caro; las
   etiquetas de plantilla son contenido de usuario, pero la interfaz no.
3. ¿API pública con Sanctum en v2.0 o se pospone? Si algún día quieres una app
   móvil, diseñar los controladores pensando en ello desde la Fase 1 sale gratis.

---

## 17. Comparativa antes / después

| | v1 (actual) | v2 (Laravel) |
|---|---|---|
| Añadir un sistema de juego | Escribir un módulo JS y desplegar | Construirlo en el navegador en 20 minutos |
| Layout de la hoja | Fijo, definido en código | Drag & drop, rejilla de 12 columnas, pestañas |
| Campos calculados | Solo el modificador, hardcodeado | Motor de fórmulas completo |
| Apariencia | Un tema para todo | Tema por plantilla, mesa y hoja |
| Compartir | Exportar JSON a mano | Catálogo público, clonar, enlaces |
| Multijugador | Ninguno (copia local) | Cuentas, mesas, roles, log compartido |
| Persistencia | localStorage (se borra al limpiar el navegador) | PostgreSQL con historial de revisiones |
| Dados | Cliente, no verificable | Servidor, autoritativo, con log |
