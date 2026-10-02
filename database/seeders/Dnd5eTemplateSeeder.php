<?php

namespace Database\Seeders;

use App\Domain\Schema\PublishTemplate;
use App\Models\Template;
use App\Models\TemplateField;
use App\Models\TemplateSection;
use App\Models\TemplateTab;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * D&D 5e como PLANTILLA, no como código.
 *
 * Esta es la prueba de fuego del diseño (§1 del plan): si el sistema de campos
 * no puede expresar una hoja de 5e, no es lo bastante potente. Lo que hace este
 * seeder es exactamente lo que hará el constructor visual en la Fase 3 —
 * escribir filas en template_tabs / sections / fields y publicar.
 *
 * Alcance actual: los ocho tipos de campo implementados. Ataques, conjuros e
 * inventario necesitan `repeater` (Fase 4).
 *
 * Los campos calculados funcionan desde la Fase 2, con las fórmulas reales de
 * §5.4 del plan: CA, competencia, iniciativa, salvaciones, percepción pasiva y
 * CD de conjuros se recalculan solos al cambiar nivel, atributos o casillas, y
 * el compilador resuelve el orden entre ellos (competencia se evalúa antes que
 * las salvaciones y la CD, que dependen de ella).
 *
 * También ejercita el resto de la Fase 2: `mod_formula` en los atributos,
 * `visible_if` en una sección entera (Magia, solo para lanzadores),
 * `readonly_if` (la experiencia se bloquea al subir por hitos), `lookup()` y
 * `switch()`.
 */
class Dnd5eTemplateSeeder extends Seeder
{
    private const CLASSES = [
        'Bárbaro', 'Bardo', 'Brujo', 'Clérigo', 'Druida', 'Explorador',
        'Guerrero', 'Hechicero', 'Mago', 'Monje', 'Paladín', 'Pícaro',
    ];

    private const ATTRIBUTES = [
        'fuerza' => 'Fuerza',
        'destreza' => 'Destreza',
        'constitucion' => 'Constitución',
        'inteligencia' => 'Inteligencia',
        'sabiduria' => 'Sabiduría',
        'carisma' => 'Carisma',
    ];

    public function run(): void
    {
        $owner = User::query()->orderBy('id')->first()
            ?? User::create([
                'name' => 'Sistema',
                'email' => 'sistema@pergamino.local',
                'password' => bin2hex(random_bytes(16)),
            ]);

        if (Template::where('slug', 'dnd-5e')->exists()) {
            $this->command?->info('La plantilla D&D 5e ya existe; no se toca.');

            return;
        }

        $template = Template::create([
            'owner_id' => $owner->id,
            'name' => 'D&D 5e',
            'slug' => 'dnd-5e',
            'tagline' => 'Hoja básica de Dungeons & Dragons 5.ª edición',
            'game_line' => 'D&D 5e',
            'visibility' => 'public',
            'is_official' => true,

            // Ajustes que consumen mod(), prof() y lookup() en las fórmulas.
            // Viven en la plantilla, no en la aplicación: un sistema que no sea
            // d20 querrá otros, y con esto no hace falta tocar código.
            'settings' => [
                'mod_base' => 10,
                'mod_divisor' => 2,
                'prof_base' => 2,
                'prof_step' => 4,
                'lookups' => [
                    'aptitud_magica' => [
                        'Bardo' => 'carisma', 'Brujo' => 'carisma', 'Hechicero' => 'carisma',
                        'Paladín' => 'carisma', 'Clérigo' => 'sabiduria', 'Druida' => 'sabiduria',
                        'Explorador' => 'sabiduria', 'Mago' => 'inteligencia',
                    ],
                    'dados_golpe' => [
                        'Bárbaro' => 12, 'Guerrero' => 10, 'Paladín' => 10, 'Explorador' => 10,
                        'Bardo' => 8, 'Clérigo' => 8, 'Druida' => 8, 'Monje' => 8,
                        'Pícaro' => 8, 'Brujo' => 8,
                        'Mago' => 6, 'Hechicero' => 6,
                    ],
                ],
            ],
        ]);

        $this->identityTab($template);
        $this->attributesTab($template);
        $this->magicTab($template);
        $this->notesTab($template);

        $version = app(PublishTemplate::class)($template, $owner, label: '1.0 — Fase 2');

        $this->command?->info("Plantilla «D&D 5e» publicada (versión {$version->version}, ".count($version->compiled_schema['fields'] ?? []).' campos).');
    }

    // ------------------------------------------------------------------ tabs

    private function identityTab(Template $template): void
    {
        $tab = $this->tab($template, 'identidad', 'Identidad', 0, default: true);

        $s = $this->section($tab, 'personaje', 'El personaje', 0, columns: 2);
        $this->field($template, $s, 'clase', 'Clase', 'select', 0, 6, config: [
            'allow_empty' => true,
            'options' => array_map(fn (string $c) => ['value' => $c, 'label' => $c], self::CLASSES),
        ]);
        $this->field($template, $s, 'nivel', 'Nivel', 'number', 1, 3, config: ['min' => 1, 'max' => 20], default: 1);
        $this->field($template, $s, 'raza', 'Raza', 'text', 2, 6);
        $this->field($template, $s, 'trasfondo', 'Trasfondo', 'text', 3, 6);
        $this->field($template, $s, 'alineamiento', 'Alineamiento', 'select', 4, 6, config: [
            'allow_empty' => true,
            'options' => array_map(
                fn (string $a) => ['value' => $a, 'label' => $a],
                ['Legal bueno', 'Neutral bueno', 'Caótico bueno',
                    'Legal neutral', 'Neutral', 'Caótico neutral',
                    'Legal malvado', 'Neutral malvado', 'Caótico malvado'],
            ),
        ]);
        $this->field($template, $s, 'experiencia', 'Experiencia', 'number', 5, 4, config: ['min' => 0],
            help: 'Bloqueada si la mesa sube de nivel por hitos.',
            readonlyIf: '@progreso_por_hitos');
        $this->field($template, $s, 'progreso_por_hitos', 'Subir por hitos', 'checkbox', 6, 2);

        $c = $this->section($tab, 'combate', 'Combate', 1, columns: 2);
        // §5.4: «Clase de armadura 5e», tal cual.
        $this->field($template, $c, 'ca', 'Clase de armadura', 'computed', 0, 4,
            formula: '10 + @destreza.mod + @armadura_bonus + if(@escudo, 2, 0)', summary: true);
        $this->field($template, $c, 'armadura_bonus', 'Bonif. de armadura', 'number', 1, 4,
            config: ['min' => 0], default: 0, help: 'Lo que la armadura suma a 10 + DES.');
        $this->field($template, $c, 'escudo', 'Escudo (+2)', 'checkbox', 2, 4);
        $this->field($template, $c, 'velocidad', 'Velocidad', 'number', 3, 4, config: ['suffix' => 'pies'], default: 30);
        $this->field($template, $c, 'pv_max', 'PV máximos', 'number', 4, 4, config: ['min' => 0]);
        $this->field($template, $c, 'pv_actual', 'PV actuales', 'number', 5, 4, config: ['min' => 0], summary: true);
        $this->field($template, $c, 'pv_temp', 'PV temporales', 'number', 6, 4, config: ['min' => 0]);
        $this->field($template, $c, 'dados_golpe', 'Dados de golpe', 'text', 7, 4, config: ['placeholder' => '3d8']);
        $this->field($template, $c, 'inspiracion', 'Inspiración', 'checkbox', 8, 4);
    }

    private function attributesTab(Template $template): void
    {
        $tab = $this->tab($template, 'atributos', 'Atributos', 1);

        $s = $this->section($tab, 'puntuaciones', 'Puntuaciones', 0, columns: 1);

        $i = 0;
        foreach (self::ATTRIBUTES as $key => $label) {
            // La MISMA mod_formula en los seis: `@self` es el propio atributo,
            // y mod() lee base y divisor de los ajustes de la plantilla.
            $this->field($template, $s, $key, $label, 'attribute', $i++, 2,
                config: ['min' => 1, 'max' => 30, 'mod_formula' => 'mod(@self)', 'show_mod' => true],
                default: 10,
                roll: '1d20 + {@'.$key.'.mod}',
                summary: $key === 'destreza',
            );
        }

        $d = $this->section($tab, 'derivados', 'Derivados', 1, columns: 2);
        // prof() usa la tabla de la plantilla; escribir la fórmula a mano
        // (2 + floor((nivel-1)/4)) daría lo mismo, pero así se ve el uso.
        $this->field($template, $d, 'competencia', 'Bonificador de competencia', 'computed', 0, 4,
            config: ['format' => 'mod'], formula: 'prof(@nivel)');
        // §5.4: «Iniciativa con dote de Alerta». Las dotes serán una lista de
        // etiquetas (Fase 4); mientras tanto, una casilla.
        $this->field($template, $d, 'iniciativa', 'Iniciativa', 'computed', 1, 4,
            config: ['format' => 'mod'], formula: '@destreza.mod + if(@dote_alerta, 5, 0)',
            roll: '1d20 + {@iniciativa}', summary: true);
        $this->field($template, $d, 'dote_alerta', 'Dote: Alerta (+5)', 'checkbox', 2, 4);
        $this->field($template, $d, 'percepcion_pasiva', 'Percepción pasiva', 'computed', 3, 4,
            formula: '10 + @sabiduria.mod', summary: true);
        $this->field($template, $d, 'dado_golpe_clase', 'Dado de golpe por clase', 'computed', 4, 4,
            formula: 'concat("d", lookup("dados_golpe", @clase, 8))',
            visibleIf: '@clase != ""');

        // §5.4: «Salvación con competencia». Cada una depende de su atributo y
        // de «competencia», que depende de «nivel»: tres saltos que el
        // compilador ordena solo.
        $sv = $this->section($tab, 'salvaciones', 'Tiradas de salvación', 2, columns: 2);

        $i = 0;
        foreach (self::ATTRIBUTES as $key => $label) {
            $this->field($template, $sv, 'salv_'.$key, $label, 'computed', $i++, 4,
                config: ['format' => 'mod'],
                formula: '@'.$key.'.mod + if(@salv_'.$key.'_comp, @competencia, 0)',
                roll: '1d20 + {@salv_'.$key.'}');
            $this->field($template, $sv, 'salv_'.$key.'_comp', 'Competente', 'checkbox', $i++, 2);
        }
    }

    /**
     * Magia: la sección entera solo aparece si la clase lanza conjuros
     * (§5.4: «mostrar espacios de conjuro solo si es lanzador»).
     */
    private function magicTab(Template $template): void
    {
        $tab = $this->tab($template, 'magia', 'Magia', 2);

        $m = $this->section($tab, 'conjuros', 'Lanzamiento de conjuros', 0, columns: 2,
            visibleIf: '@clase in ["Bardo", "Brujo", "Clérigo", "Druida", "Explorador", "Hechicero", "Mago", "Paladín"]');

        // La aptitud sale de la clase con lookup(), y switch() elige el
        // modificador que le corresponde.
        $ability = 'switch(@aptitud_conjuros, "inteligencia", @inteligencia.mod, '
            .'"sabiduria", @sabiduria.mod, @carisma.mod)';

        $this->field($template, $m, 'aptitud_conjuros', 'Aptitud mágica', 'computed', 0, 4,
            formula: 'lookup("aptitud_magica", @clase, "carisma")');
        // Depende de «competencia», que a su vez depende de «nivel», y de
        // «aptitud_conjuros»: el compilador resuelve la cadena y las evalúa en
        // orden.
        $this->field($template, $m, 'cd_conjuros', 'CD de salvación de conjuros', 'computed', 1, 4,
            formula: '8 + @competencia + '.$ability);
        $this->field($template, $m, 'ataque_conjuros', 'Bonif. de ataque con conjuros', 'computed', 2, 4,
            config: ['format' => 'mod'], formula: '@competencia + '.$ability,
            roll: '1d20 + {@ataque_conjuros}');
        $this->field($template, $m, 'conjuros_preparados', 'Conjuros preparados', 'textarea', 3, 12,
            config: ['rows' => 4]);
    }

    private function notesTab(Template $template): void
    {
        $tab = $this->tab($template, 'notas', 'Rasgos y notas', 3);

        $s = $this->section($tab, 'rasgos', 'Rasgos', 0, columns: 1);
        $this->field($template, $s, 'rasgos', 'Rasgos y dotes', 'textarea', 0, 12, config: ['rows' => 6]);
        $this->field($template, $s, 'idiomas', 'Idiomas', 'text', 1, 12);

        $p = $this->section($tab, 'personalidad', 'Personalidad', 1, columns: 2);
        $this->field($template, $p, 'rasgos_personalidad', 'Rasgos de personalidad', 'textarea', 0, 6, config: ['rows' => 3]);
        $this->field($template, $p, 'ideales', 'Ideales', 'textarea', 1, 6, config: ['rows' => 3]);
        $this->field($template, $p, 'vinculos', 'Vínculos', 'textarea', 2, 6, config: ['rows' => 3]);
        $this->field($template, $p, 'defectos', 'Defectos', 'textarea', 3, 6, config: ['rows' => 3]);

        $e = $this->section($tab, 'equipo', 'Equipo', 2, columns: 1);
        $this->field($template, $e, 'equipo', 'Equipo', 'textarea', 0, 12, config: ['rows' => 6],
            help: 'En la Fase 4 esto pasará a ser una tabla con peso y cantidad.');
        $this->field($template, $e, 'monedas', 'Monedas', 'text', 1, 12, config: ['placeholder' => '25 po, 3 pp']);
    }

    // --------------------------------------------------------------- helpers

    private function tab(Template $t, string $key, string $label, int $pos, bool $default = false): TemplateTab
    {
        return TemplateTab::create([
            'template_id' => $t->id,
            'key' => $key,
            'label' => $label,
            'position' => $pos,
            'is_default' => $default,
        ]);
    }

    private function section(
        TemplateTab $tab,
        string $key,
        string $label,
        int $pos,
        int $columns = 1,
        ?string $visibleIf = null,
    ): TemplateSection {
        return TemplateSection::create([
            'template_tab_id' => $tab->id,
            'key' => $key,
            'label' => $label,
            'position' => $pos,
            'columns' => $columns,
            'visible_if' => $visibleIf,
        ]);
    }

    private function field(
        Template $t,
        TemplateSection $s,
        string $key,
        string $label,
        string $type,
        int $pos,
        int $span,
        array $config = [],
        mixed $default = null,
        ?string $formula = null,
        ?string $roll = null,
        ?string $help = null,
        bool $summary = false,
        ?string $visibleIf = null,
        ?string $readonlyIf = null,
    ): TemplateField {
        return TemplateField::create([
            'template_id' => $t->id,
            'template_section_id' => $s->id,
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'position' => $pos,
            'col_span' => $span,
            'config' => $config ?: null,
            'default_value' => $default === null ? null : ['value' => $default],
            'formula' => $formula,
            'roll_expression' => $roll,
            'help_text' => $help,
            'is_summary' => $summary,
            'visible_if' => $visibleIf,
            'readonly_if' => $readonlyIf,
        ]);
    }
}
