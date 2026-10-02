# Puesta en marcha — Fases 0 a 5

## 1. Esqueleto de Laravel (lo ejecutas tú)

En la terminal de Laragon, con la carpeta `Pergamino` **vacía**:

```
cd D:\laragon\laragon\www\Pergamino
composer create-project laravel/laravel .
composer require livewire/livewire
composer require pestphp/pest pestphp/pest-plugin-laravel --dev --with-all-dependencies
php artisan pest:install
```

> `create-project` exige que la carpeta esté vacía. Por eso este código va
> **después**, nunca antes.

## 2. Base de datos (PostgreSQL 16+)

El proyecto usa PostgreSQL desde el 17 de agosto de 2026 (§3.1 y §15.5 del
plan). En Laragon se añade desde *Herramientas → Quick add*.

```
psql -U postgres -c "CREATE USER pergamino_user WITH PASSWORD 'cambia-esto';"
psql -U postgres -c "CREATE DATABASE pergamino_db OWNER pergamino_user;"
```

Y en `.env`:

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=pergamino_db
DB_USERNAME=pergamino_user
DB_PASSWORD=cambia-esto
```

**Ojo con dos cosas:**

- El puerto de PostgreSQL es el **5432**; el 3306 es el de MySQL.
- PHP necesita las extensiones `pdo_pgsql` y `pgsql`. En Windows vienen
  compiladas pero **desactivadas**: hay que quitar el `;` de
  `extension=pdo_pgsql` y `extension=pgsql` en el `php.ini` del PHP que uses
  (Laragon: *PHP → Extensions*). Compruébalo con `php -m | findstr pgsql`.

Los tests no necesitan nada de esto: corren en SQLite en memoria
(`phpunit.xml`).

## 3. Copiar este código encima

Los archivos de esta entrega se colocan sobre el esqueleto, sobrescribiendo
`app/Models/User.php`, `routes/web.php`, `resources/css/app.css` y
`database/seeders/DatabaseSeeder.php`. Todo lo demás es nuevo.

## 4. Arrancar

```
php artisan migrate:fresh --seed
npm install
npm run dev
```

`migrate:fresh` borra y recrea todo. Hace falta si ya habías migrado antes del
paso a PostgreSQL o de la Fase 2: las migraciones se editaron en su sitio
(`json` → `jsonb`) porque aún no había datos reales que conservar, y la
plantilla de 5e solo se siembra si no existe.

Y abre `http://pergamino.test` (Laragon lo crea solo) o `php artisan serve`.

Usuario de prueba: **dj@pergamino.local** / **pergamino**

## 5. Comprobar que todo está bien

```
php artisan test
npm run test:js
npm run test:formulas
php verify-domain.php
```

Son distintas y todas importan:

- `php artisan test` — Pest, con base de datos. Incluye `SheetParityTest`, que
  recalcula la hoja de 5e completa con PHP **y con Node** y exige el mismo
  resultado: orden de cálculo, modificadores, condiciones, tiradas y formato.
- `npm run test:js` — Vitest: la batería compartida y el resto del motor de JS.
- `npm run test:formulas` (`diff-engines.sh`) — la importante. Ejecuta la misma
  batería (205 casos evaluables) contra el motor de PHP y el de JavaScript y **compara las
  dos salidas entre sí**. Si algún día divergen, salta aquí.
- `php verify-domain.php` — 59 comprobaciones de la lógica pura, **sin Laravel
  ni base de datos**.

### Los tests contra PostgreSQL

Por defecto Pest usa SQLite en memoria, pero SQLite acepta cosas que
PostgreSQL rechaza (un `\u0000` dentro de `jsonb`, por ejemplo). Para probar
contra tu PostgreSQL **sin tocar tus datos**, usa un esquema aparte:

```
psql -U pergamino_user -d pergamino_db -c "CREATE SCHEMA IF NOT EXISTS testing;"
```

y lanza Pest con `DB_CONNECTION=pgsql` y `DB_SEARCH_PATH=testing` (los tests
borran y recrean las tablas, pero solo las de ese esquema). En PowerShell:

```
$env:DB_CONNECTION="pgsql"; $env:DB_SEARCH_PATH="testing"; vendor/bin/pest
```

### CI

`.github/workflows/ci.yml` lo ejecuta todo en cada push a `main`, `develop` o
`fase-*` y en cada pull request: Pint, Vitest, el diferencial PHP↔JS,
`verify-domain.php` y Pest dos veces, contra SQLite y contra PostgreSQL 16.

**Versión de PHP:** el proyecto se desarrolla y se prueba con **PHP 8.5**
(8.5.9 en local, 8.5 en CI). El PHP 8.3 que cita el plan está descartado; el
`composer.lock` actual exige además PHP 8.4.1 como mínimo (Symfony 8).

Si tocas el lenguaje de fórmulas, regenera la batería antes:

```
php tests/fixtures/build-formula-cases.php
```

---

## Qué hay implementado

**Fase 0**

- Registro, login y logout con componentes Livewire propios
- Layout base con la paleta "pergamino" en variables CSS (claro y oscuro)
- Panel con las hojas del usuario

**Fase 1**

- Las 20 tablas del §3 del plan, con sus índices
- 14 modelos Eloquent con relaciones y casts
- Policies de plantilla, hoja y mesa
- `SchemaCompiler`: aplana el árbol de la plantilla en `compiled_schema`
- `DependencyGraph`: orden topológico de los campos calculados, con detección
  de ciclos y mensajes que nombran los campos implicados
- `SchemaValidator`: el panel de avisos del futuro constructor
- `PublishTemplate`: congela el borrador en una versión inmutable
- Editor de hoja que renderiza los 8 tipos de campo básicos desde el esquema
- Historial de revisiones con poda automática a las 30 últimas
- Plantilla semilla de D&D 5e **construida con el sistema de campos**, no en código

**Fase 2 — el motor de fórmulas**

- Lenguaje completo: aritmética, comparaciones, lógica con cortocircuito,
  ternario, listas, `in` / `contains`, indexación `@lista[*].columna`
- 30 funciones en lista blanca, incluidas `mod()`, `prof()` y `lookup()`, que
  leen su configuración de la plantilla
- Campos calculados, `visible_if`, `readonly_if` y plantillas de tirada
  interpoladas (`1d20 + {@destreza.mod}` → `1d20 + 2`)
- Evaluador espejo en JavaScript para feedback instantáneo
- Una fórmula rota deja su campo en «—» con el motivo, sin tumbar la hoja
- Aritmética sobre listas: `sum(@inventario[*].peso * @inventario[*].cantidad)`
- `attribute` con `mod_formula`; `@self` es el propio campo, así que la misma
  fórmula (`mod(@self)`) vale para los seis atributos
- `visible_if` también en secciones; `readonly_if` impuesto al guardar, no solo
  en el input
- Formato de los calculados (`config.format`: `int`, `mod`, `percent`, `text`)
- **Recalculo instantáneo en el editor**: el evaluador de JS está conectado
  (`resources/js/formula/sheet.js`). Al teclear, los derivados cambian sin ir al
  servidor; al salir del campo se guarda y el servidor recalcula y manda

**Paso a PostgreSQL** (§3.1): `jsonb` en las 13 columnas JSON, índice GIN en
`sheets.data`, sin `->after()`, y correos y códigos de mesa normalizados para
que las mayúsculas no creen duplicados.

**Fase 3 — el constructor visual** (`/plantillas` → «Nueva plantilla»)

- Pantalla de constructor (`/plantillas/{uuid}/constructor`): paleta, lienzo
  con pestañas → secciones → rejilla de 12 columnas, e inspector.
- Arrastrar y soltar en los tres niveles con SortableJS (directiva Alpine
  `x-sortable`, `resources/js/builder/sortable.js`): pestañas, secciones por su
  asa ⠿, campos dentro de su sección o hacia otra. Desde la paleta, arrastrando
  o con un clic. Para llevar algo a otra pestaña, el inspector tiene un selector.
- Inspector con la configuración propia de cada tipo (§4), ancho, valor por
  defecto, fórmula, tirada, `visible_if`, `readonly_if`, `mod_formula`… Cada
  fórmula muestra sus problemas debajo del input al salir de él.
- Ajustes de la plantilla: `mod()`, `prof()` y tablas de `lookup()` en JSON.
- Duplicar campo y añadir en bloque (una línea por campo) (§6.2, puntos 2 y 3).
- Panel de validación en vivo; un clic en un aviso selecciona el campo.
- Borrador → publicar → `template_version`, con nombre y notas de la versión.
  Publicar se bloquea mientras haya errores.
- Vista previa en vivo, escritorio o móvil: es la hoja real (el mismo
  `livewire/sheet/_body` que el editor), con datos de prueba que no se guardan.

Toda la lógica está en `app/Domain/Builder/TemplateEditor.php`, sin interfaz y
con tests; el componente `App\Livewire\Template\Builder` solo traduce clics y
arrastres a llamadas. El plan lo repartía en seis componentes Livewire; aquí es
uno (más la vista previa) porque todos comparten la selección y la pestaña.

**El formato del esquema compilado es ahora el 2** (`CompiledSchema::VERSION`).
Las versiones publicadas son inmutables y conservan su formato:
`CompiledSchema::upgrade()` las lee al vuelo. La caché del esquema incluye el
formato y la fecha de publicación, no solo el id, para no servir un esquema
ajeno cuando un `migrate:fresh` reutiliza ids.

**Fase 4 — tipos de campo avanzados**

Todos los tipos de §4 salvo `reference` (enlazar con otra hoja necesita las
mesas, Fase 6). Cada uno tiene su saneado (`app/Domain/Sheet/FieldValue.php`),
lo que calcula (`FieldDerivation.php`, gemelo en `resources/js/formula/fields.js`),
su configuración en el inspector (`app/Domain/Builder/FieldConfig.php`) y su
parcial en `resources/views/fields`.

| Tipo | En las fórmulas |
|---|---|
| `resource` (PV, maná) — actual / máximo / temporales, barra, −/+ | `@pv.current`, `.max`, `.temp`, `.pct` |
| `track` (estrés, salud) — casillas con estados: vacía → superficial → agravado | `@salud.marked`, `.boxes` |
| `clock` — reloj de segmentos | `@reloj` (segmentos llenos) |
| `counter` — contador con −/+ | `@usos` |
| `progress` — experiencia con umbrales | `@xp.level`, `.next`, `.pct` |
| `currency` — monedas con cambio | `@bolsa.po`, `@bolsa.total` |
| `proficiency` — nivel de competencia + ajuste | `@sigilo.bonus`, `.level` |
| `derived_list` — lista fija con competencia (las 18 habilidades) | `@habilidades.sigilo.bonus` |
| `repeater` — tabla de filas con columnas calculadas | `@inv[*].peso`, `sum(@inv[*].total)` |
| `multiselect`, `tags`, `color`, `dice_button`, `image`, `portrait` | |

- **Fórmulas en la configuración**: el máximo de un recurso
  (`@nivel * 8 + @constitucion.mod`), las casillas de unas marcas
  (`@resistencia.marked + 3`), la base y el bonificador de cada nivel de
  competencia, y las columnas calculadas de una tabla, donde `@row` es la fila
  (`@row.peso * @row.cantidad`). Se compilan, entran en el orden de cálculo y
  pasan por el validador como cualquier otra. `row` es palabra reservada.
- **Las listas se editan como texto** en el inspector, una por línea:
  `peso | Peso | número`, `total | Total | = @row.peso * 2`,
  `comp | Competente | @competencia`.
- **El servidor sanea cada valor** a la forma de su tipo antes de guardar: un
  recurso siempre es `{current, max, temp}`, una tabla no pasa de `max_rows`
  ni guarda columnas inventadas, el PV actual no pasa del máximo calculado.
- **Retratos e imágenes**: el tipo se comprueba por el contenido del archivo;
  la imagen se reescribe en WebP sin EXIF con Intervention (driver GD) y a
  1600 px como mucho; se guarda en `storage/app/private` y se sirve por
  `/media/{id}` con URL firmada. Una hoja solo acepta imágenes de su dueño.
- **Descansos** corto y largo en la cabecera de la hoja, si algún recurso o
  contador tiene `reset_on`.
- **Bloques prefabricados** (§6.2, punto 4) en la paleta: 6 atributos d20,
  nivel y competencia, PV/CA/iniciativa, las 18 habilidades, conjuros,
  inventario con peso, reloj, estrés de FATE, salud de Vampiro y estrés de
  Blades. Si una clave ya existe se renombra y las fórmulas del bloque se
  reescriben.
- **Plantillas oficiales** (`php artisan db:seed`): D&D 5e (versión 2.0),
  FATE Básico, Vampiro V5 y Blades in the Dark. Las que ya existen no se tocan:
  para ver la nueva de 5e en una base con datos, hace falta borrarla antes o
  crear la hoja en una base nueva.

**Fase 5 — apariencia y exportación**

- **Temas** (§6.3, `app/Domain/Theme`): un JSON con propiedades en lista
  blanca (colores `#rrggbb`, tipografías del catálogo, números acotados) que
  `ThemeCompiler` convierte en variables CSS con ámbito `[data-sheet="uuid"]`.
  Nunca se acepta CSS del usuario. Toda la hoja ya usaba `var(--pg-*)`, así
  que cambiar de tema no toca ni una vista.
- **Siete presets** con paleta clara y oscura: Pergamino, Grimorio oscuro,
  Cyberpunk neón, Minimal papel, Sci-fi terminal, Cómic y Máquina de escribir.
  Cada tema elige además texturas, bordes, sombras, densidad, ancho, modo
  claro/oscuro (o según el dispositivo) e imagen de fondo con opacidad.
- **Cascada** preset → plantilla → mesa → hoja. Una hoja solo cambia el acento
  y el modo (botón «Apariencia» en su cabecera). La capa de la mesa ya funciona
  (`campaigns.theme_override`) y tendrá interfaz con las mesas, en la Fase 6.
- **El tema se lee de la plantilla viva**, no de la versión publicada: es
  apariencia, no estructura, y cambiarlo no obliga a publicar ni a migrar
  hojas. La versión guarda una copia para la exportación.
- **Editor de apariencia** en `/plantillas/{uuid}/apariencia` (botón en el
  constructor y en el listado), con la hoja real como vista previa.
- **Tipografías auto-alojadas**: Cinzel, EB Garamond, IM Fell English, Inter,
  JetBrains Mono, Bebas Neue y Special Elite, de los paquetes `@fontsource`
  (npm). Vite las empaqueta en `public/build`: ni Google Fonts ni CDN.
- **Imprimir** (`/hojas/{uuid}/imprimir`) y **PDF** (`/hojas/{uuid}/pdf`, con
  `?marca=1` para la marca de agua): todas las pestañas, una por página, con
  los valores ya formateados. El PDF lo hace **dompdf** (PHP puro, sirve en
  cualquier hosting) sin acceso a red; los retratos van incrustados. Como
  dompdf no entiende CSS Grid ni variables CSS, la vista de impresión usa
  tablas y los colores literales de la paleta clara del tema.
- **JSON** versionado (`pergamino.template` y `pergamino.sheet`, versión 1):
  exportar e importar plantillas (en el listado de plantillas) y hojas (menú
  «Imprimir / exportar» de la hoja; importar, en el panel). Lo importado pasa
  por los mismos saneados que lo que llega del inspector o del editor; una
  plantilla importada es un borrador nuevo y privado. Las imágenes no viajan.
- Las plantillas oficiales traen cada una su tema: 5e en Pergamino, FATE en
  Minimal papel, Vampiro en Grimorio oscuro y Blades en Máquina de escribir
  oscura.

Los componentes de estilo (`.pg-input`, `.pg-btn`…) están ahora en la capa
`components` de Tailwind: una utilidad como `w-16` puede ajustarlos. Antes,
fuera de capa, `.pg-input { width: 100% }` ganaba siempre y algunos inputs
estrechos de la Fase 4 se desbordaban.

### Pruebas en un navegador real

Para probar la aplicación sin tocar `pergamino_db`, usa una base SQLite
temporal. Ojo: **`php artisan serve` descarta las variables de entorno que
también están en `.env`**, así que `DB_CONNECTION=sqlite php artisan serve`
acabaría hablando con PostgreSQL. Usa el servidor de PHP con un router que las
fije antes de que arranque Laravel (Dotenv no pisa las que ya existen):

```php
// router.php — php -S 127.0.0.1:8766 router.php   (desde public/)
foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => 'C:/ruta/e2e.sqlite',
          'SESSION_DRIVER' => 'file', 'APP_ENV' => 'local'] as $k => $v) {
    putenv("$k=$v"); $_ENV[$k] = $_SERVER[$k] = $v;
}
return require __DIR__.'/../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
```

## Qué NO hay todavía (y es intencionado)

- **Del constructor, lo que el plan deja para después**: historial con diff
  entre versiones y migración asistida de hojas a una versión nueva (§6.2,
  puntos 8 y 10). Importar el formato de la aplicación antigua (§6.2.7 y §13)
  llega con la migración de datos, en la Fase 8. Hoy una hoja se queda en su versión
  y solo ve el aviso de que hay otra más nueva.
- **Renombrar una clave no reescribe las fórmulas que la usan**: el panel de
  validación señala cuáles se rompen.
- **`reference`** (enlace a otra hoja): llega con las mesas.
- **Exportar la ficha como PNG/JPG**: fuera de alcance sin navegador headless
  en el servidor (§6.4). El PDF y «imprimir a PDF» del navegador lo cubren.
- **Los botones de tirada muestran la expresión pero no tiran**: el motor de
  dados es la Fase 6. Una reserva de Vampiro (`{@fuerza.marked + …}d10`) ya
  se resuelve a `5d10`.
- **Dados y mesas** (Fase 6). Las tablas ya existen.

## Dos detalles de diseño que conviene no perder

**1. PHP analiza, JavaScript solo evalúa.**

El plan daba por hecho dos motores de fórmulas completos, uno en cada lenguaje,
y señalaba su divergencia como el riesgo principal del proyecto. Esta
implementación lo evita: una fórmula se analiza **una sola vez**, en PHP, al
publicar la versión de plantilla, y el AST resultante se guarda dentro de
`compiled_schema`. El navegador recibe el mismo árbol que evaluó el servidor.

Así no puede haber discrepancias de gramática — precedencia, literales, errores
de sintaxis — porque solo hay un analizador. Lo único que queda en paralelo son
las coerciones y las funciones, y eso es exactamente lo que cubre
`diff-engines.sh`. Ver `app/Domain/Formula/README.md`.

**2. `DependencyGraph` es una clase pura.**

No toca Eloquent, ni la base de datos, ni Laravel. Eso permite probarla aislada,
y es la razón de que la Fase 2 haya podido reutilizarla tal cual, cambiando solo
la fuente de las referencias (ahora salen del AST, antes de una expresión
regular). Conviene que siga sin dependencias.
