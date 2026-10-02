# Puesta en marcha — Fases 0, 1 y 2 (§1–§5 del plan)

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

## Qué NO hay todavía (y es intencionado)

- **Constructor visual** (Fase 3). Las plantillas se crean por seeder.
- **Tipos de campo avanzados** (Fase 4): `repeater`, `resource`, `track`…
  Están declarados en `FieldType` y el validador ya los conoce; en la hoja se
  muestran con un aviso en vez de romper.
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
