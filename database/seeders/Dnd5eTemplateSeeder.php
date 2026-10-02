<?php

namespace Database\Seeders;

use App\Domain\Builder\Prefabs;
use App\Models\Template;
use Database\Seeders\Concerns\BuildsTemplates;
use Illuminate\Database\Seeder;

/**
 * D&D 5e como PLANTILLA, no como código.
 *
 * Esta es la prueba de fuego del diseño (§1 del plan): si el sistema de campos
 * no puede expresar una hoja de 5e, no es lo bastante potente. Lo que hace este
 * seeder es exactamente lo que hace el constructor visual: escribir filas en
 * template_tabs / sections / fields y publicar.
 *
 * Fórmulas (Fase 2), con las reales de §5.4 del plan: CA, competencia,
 * iniciativa, salvaciones, percepción pasiva y CD de conjuros se recalculan
 * solos, y el compilador resuelve el orden entre ellos. También `mod_formula`,
 * `visible_if` en una sección entera (Magia, solo para lanzadores),
 * `readonly_if` (la experiencia se bloquea al subir por hitos), `lookup()` y
 * `switch()`.
 *
 * Tipos de rol (Fase 4): PV como recurso con descanso largo, salvaciones
 * contra la muerte como marcas, experiencia con los umbrales de nivel, las 18
 * habilidades en una lista derivada, ataques, conjuros e inventario como
 * tablas con columnas calculadas, monedas, idiomas como etiquetas y retrato.
 */
class Dnd5eTemplateSeeder extends Seeder
{
    use BuildsTemplates;

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
        $template = $this->newTemplate('dnd-5e', [
            'name' => 'D&D 5e',
            'tagline' => 'Hoja de Dungeons & Dragons 5.ª edición',
            'game_line' => 'D&D 5e',

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

        if (! $template) {
            return;
        }

        $this->identityTab($template);
        $this->attributesTab($template);
        $this->equipmentTab($template);
        $this->magicTab($template);
        $this->notesTab($template);

        $this->publish($template, '2.0 — Fase 4');
    }

    // ------------------------------------------------------------------ tabs

    private function identityTab(Template $template): void
    {
        $tab = $this->tab($template, 'identidad', 'Identidad', 0, default: true);

        $s = $this->section($tab, 'personaje', 'El personaje', 0, columns: 2);
        $this->field($template, $s, 'retrato', 'Retrato', 'portrait', 0, 3, config: ['shape' => 'rounded', 'frame' => true]);
        $this->field($template, $s, 'clase', 'Clase', 'select', 1, 5, config: [
            'allow_empty' => true,
            'options' => array_map(fn (string $c) => ['value' => $c, 'label' => $c], self::CLASSES),
        ]);
        $this->field($template, $s, 'nivel', 'Nivel', 'number', 2, 4, config: ['min' => 1, 'max' => 20], default: 1,
            help: 'Por experiencia, el que corresponde se ve bajo la barra.');
        $this->field($template, $s, 'raza', 'Raza', 'text', 3, 5);
        $this->field($template, $s, 'trasfondo', 'Trasfondo', 'text', 4, 4);
        $this->field($template, $s, 'alineamiento', 'Alineamiento', 'select', 5, 5, config: [
            'allow_empty' => true,
            'options' => array_map(
                fn (string $a) => ['value' => $a, 'label' => $a],
                ['Legal bueno', 'Neutral bueno', 'Caótico bueno',
                    'Legal neutral', 'Neutral', 'Caótico neutral',
                    'Legal malvado', 'Neutral malvado', 'Caótico malvado'],
            ),
        ]);
        // Los umbrales de 5e: @experiencia.level es el nivel que corresponde.
        $this->field($template, $s, 'experiencia', 'Experiencia', 'progress', 6, 6, config: ['thresholds' => [
            0, 300, 900, 2700, 6500, 14000, 23000, 34000, 48000, 64000,
            85000, 100000, 120000, 140000, 165000, 195000, 225000, 265000, 305000, 355000,
        ]], help: 'Bloqueada si la mesa sube de nivel por hitos.', readonlyIf: '@progreso_por_hitos');
        $this->field($template, $s, 'progreso_por_hitos', 'Subir por hitos', 'checkbox', 7, 3);

        $c = $this->section($tab, 'combate', 'Combate', 1, columns: 2);
        $this->field($template, $c, 'pv', 'Puntos de golpe', 'resource', 0, 6,
            config: ['show_temp' => true, 'reset_on' => 'long'], summary: true);
        // §5.4: «Clase de armadura 5e», tal cual.
        $this->field($template, $c, 'ca', 'Clase de armadura', 'computed', 1, 3,
            formula: '10 + @destreza.mod + @armadura_bonus + if(@escudo, 2, 0)', summary: true);
        $this->field($template, $c, 'velocidad', 'Velocidad', 'number', 2, 3, config: ['suffix' => 'pies'], default: 30);
        $this->field($template, $c, 'armadura_bonus', 'Bonif. de armadura', 'number', 3, 3,
            config: ['min' => 0], default: 0, help: 'Lo que la armadura suma a 10 + DES.');
        $this->field($template, $c, 'escudo', 'Escudo (+2)', 'checkbox', 4, 3);
        // Tantos dados como niveles; se recuperan al descansar.
        $this->field($template, $c, 'dados_golpe', 'Dados de golpe', 'resource', 5, 6,
            config: ['show_temp' => false, 'max_formula' => '@nivel', 'reset_on' => 'long'],
            roll: '1d{lookup("dados_golpe", @clase, 8)} + {@constitucion.mod}');
        $this->field($template, $c, 'salvacion_muerte_exitos', 'Salvaciones contra la muerte: éxitos', 'track', 6, 3,
            config: ['boxes' => 3, 'shape' => 'dot', 'states' => ['Éxito']]);
        $this->field($template, $c, 'salvacion_muerte_fallos', 'Fallos', 'track', 7, 3,
            config: ['boxes' => 3, 'shape' => 'dot', 'states' => ['Fallo']]);
        $this->field($template, $c, 'inspiracion', 'Inspiración', 'checkbox', 8, 3);
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
        // §5.4: «Iniciativa con dote de Alerta».
        $this->field($template, $d, 'iniciativa', 'Iniciativa', 'computed', 1, 4,
            config: ['format' => 'mod'], formula: '@destreza.mod + if(@dote_alerta, 5, 0)',
            roll: '1d20 + {@iniciativa}', summary: true);
        $this->field($template, $d, 'dote_alerta', 'Dote: Alerta (+5)', 'checkbox', 2, 4);
        $this->field($template, $d, 'percepcion_pasiva', 'Percepción pasiva', 'computed', 3, 4,
            formula: '10 + @habilidades.percepcion.bonus', summary: true);
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

        // Las 18 habilidades en un campo, con competencia y pericia: las mismas
        // que el bloque prefabricado del constructor.
        $h = $this->section($tab, 'habilidades', 'Habilidades', 3, columns: 1);
        $skills = collect(Prefabs::get('habilidades_5e')['fields'])->firstWhere('key', 'habilidades');
        $this->field($template, $h, 'habilidades', 'Habilidades', 'derived_list', 0, 12, config: $skills['config']);
    }

    private function equipmentTab(Template $template): void
    {
        $tab = $this->tab($template, 'equipo', 'Equipo', 2);

        $a = $this->section($tab, 'ataques', 'Ataques', 0);
        $this->field($template, $a, 'ataques', 'Ataques', 'repeater', 0, 12, config: [
            'max_rows' => 20,
            'columns' => [
                ['key' => 'arma', 'label' => 'Arma', 'type' => 'text'],
                ['key' => 'atributo', 'label' => 'Atributo', 'type' => 'select', 'options' => ['Fuerza', 'Destreza']],
                ['key' => 'competente', 'label' => 'Comp.', 'type' => 'checkbox'],
                ['key' => 'magico', 'label' => 'Mágico', 'type' => 'number'],
                ['key' => 'dano', 'label' => 'Daño', 'type' => 'text'],
                // El bonificador de cada fila mira fuera de la tabla: el
                // atributo elegido y la competencia del personaje.
                ['key' => 'ataque', 'label' => 'Ataque', 'type' => 'computed',
                    'formula' => 'if(@row.atributo == "Destreza", @destreza.mod, @fuerza.mod) + if(@row.competente, @competencia, 0) + @row.magico'],
            ],
        ]);

        $inventory = Prefabs::get('inventario')['fields'];
        $e = $this->section($tab, 'inventario', 'Inventario', 1, columns: 2);
        $this->field($template, $e, 'inventario', 'Inventario', 'repeater', 0, 12, config: $inventory[0]['config']);
        $this->field($template, $e, 'carga', 'Carga', 'computed', 1, 4, formula: 'sum(@inventario[*].total)',
            config: ['format' => 'int']);
        $this->field($template, $e, 'capacidad', 'Capacidad de carga', 'computed', 2, 4, formula: '@fuerza * 15',
            config: ['format' => 'int']);
        $this->field($template, $e, 'sobrecargado', 'Sobrecargado', 'computed', 3, 4,
            formula: 'if(@carga > @capacidad, "Sí", "No")');
        $this->field($template, $e, 'monedas', 'Monedas', 'currency', 4, 12, config: $inventory[2]['config']);
    }

    /**
     * Magia: la sección entera solo aparece si la clase lanza conjuros
     * (§5.4: «mostrar espacios de conjuro solo si es lanzador»).
     */
    private function magicTab(Template $template): void
    {
        $tab = $this->tab($template, 'magia', 'Magia', 3);

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

        $this->field($template, $m, 'espacios', 'Espacios de conjuro', 'repeater', 3, 12, config: [
            'max_rows' => 9,
            'columns' => [
                ['key' => 'nivel', 'label' => 'Nivel', 'type' => 'number'],
                ['key' => 'total', 'label' => 'Total', 'type' => 'number'],
                ['key' => 'gastados', 'label' => 'Gastados', 'type' => 'number'],
                ['key' => 'quedan', 'label' => 'Quedan', 'type' => 'computed', 'formula' => 'max(0, @row.total - @row.gastados)'],
            ],
        ]);

        $spells = collect(Prefabs::get('conjuros')['fields'])->firstWhere('key', 'conjuros');
        $this->field($template, $m, 'conjuros_conocidos', 'Conjuros', 'repeater', 4, 12, config: $spells['config']);
        $this->field($template, $m, 'conjuros_preparados', 'Preparados', 'computed', 5, 4,
            formula: 'sum(@conjuros_conocidos[*].preparado)');
    }

    private function notesTab(Template $template): void
    {
        $tab = $this->tab($template, 'notas', 'Rasgos y notas', 4);

        $s = $this->section($tab, 'rasgos', 'Rasgos', 0, columns: 1);
        $this->field($template, $s, 'rasgos', 'Rasgos y dotes', 'textarea', 0, 12, config: ['rows' => 6]);
        $this->field($template, $s, 'idiomas', 'Idiomas', 'tags', 1, 12, config: ['suggestions' => [
            'Común', 'Élfico', 'Enano', 'Gigante', 'Gnomo', 'Goblin', 'Mediano', 'Orco',
            'Abisal', 'Celestial', 'Dracónico', 'Infernal', 'Primordial', 'Silvano',
        ]]);

        $p = $this->section($tab, 'personalidad', 'Personalidad', 1, columns: 2);
        $this->field($template, $p, 'rasgos_personalidad', 'Rasgos de personalidad', 'textarea', 0, 6, config: ['rows' => 3]);
        $this->field($template, $p, 'ideales', 'Ideales', 'textarea', 1, 6, config: ['rows' => 3]);
        $this->field($template, $p, 'vinculos', 'Vínculos', 'textarea', 2, 6, config: ['rows' => 3]);
        $this->field($template, $p, 'defectos', 'Defectos', 'textarea', 3, 6, config: ['rows' => 3]);
    }
}
