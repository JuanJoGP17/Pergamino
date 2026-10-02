<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\BuildsTemplates;
use Illuminate\Database\Seeder;

/**
 * Blades in the Dark como plantilla (entregable de la Fase 4: el catálogo de
 * campos cubre Blades).
 *
 * Lo que lo hace Blades y no d20:
 *   - Acciones de 0 a 4 puntos agrupadas en tres atributos; el valor del
 *     atributo es cuántas de sus acciones tienen al menos un punto.
 *   - Estrés (9) y trauma (4) como marcas, daño en tres niveles y el reloj de
 *     sanación.
 *   - Experiencia por atributo como marcas, monedas y alijo.
 */
class BladesTemplateSeeder extends Seeder
{
    use BuildsTemplates;

    private const ATTRIBUTES = [
        'perspicacia' => ['Perspicacia', ['cazar' => 'Cazar', 'estudiar' => 'Estudiar', 'examinar' => 'Examinar', 'trastear' => 'Trastear']],
        'proeza' => ['Proeza', ['sutileza' => 'Sutileza', 'merodear' => 'Merodear', 'escaramuza' => 'Escaramuza', 'destrozar' => 'Destrozar']],
        'determinacion' => ['Determinación', ['sintonizar' => 'Sintonizar', 'ordenar' => 'Ordenar', 'codearse' => 'Codearse', 'persuadir' => 'Persuadir']],
    ];

    public function run(): void
    {
        $t = $this->newTemplate('blades-in-the-dark', [
            'name' => 'Blades in the Dark',
            'tagline' => 'Acciones, estrés y trauma, daño por niveles y relojes',
            'game_line' => 'Blades in the Dark',
            'settings' => [],
        ]);

        if (! $t) {
            return;
        }

        $tab = $this->tab($t, 'canalla', 'Canalla', 0, default: true);

        $id = $this->section($tab, 'identidad', 'Identidad', 0, columns: 2);
        $this->field($t, $id, 'nombre_personaje', 'Nombre', 'text', 0, 4);
        $this->field($t, $id, 'alias', 'Alias', 'text', 1, 4);
        $this->field($t, $id, 'arquetipo', 'Arquetipo', 'select', 2, 4, config: [
            'allow_empty' => true,
            'options' => array_map(fn ($a) => ['value' => $a, 'label' => $a],
                ['Asesino', 'Matón', 'Sabueso', 'Charlatán', 'Araña', 'Sombra', 'Susurro']),
        ], summary: true);
        $this->field($t, $id, 'herencia', 'Herencia', 'text', 3, 4);
        $this->field($t, $id, 'trasfondo', 'Trasfondo', 'text', 4, 4);
        $this->field($t, $id, 'vicio', 'Vicio', 'text', 5, 4);

        // Tres atributos, cada uno con sus cuatro acciones de 0 a 4 puntos.
        $acc = $this->section($tab, 'acciones', 'Acciones', 1, columns: 3);
        $pos = 0;
        foreach (self::ATTRIBUTES as $key => [$label, $actions]) {
            $this->field($t, $acc, $key, $label, 'derived_list', $pos++, 4, config: [
                'items' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys($actions), $actions),
                'levels' => array_map(
                    fn (int $n) => ['key' => "p{$n}", 'label' => $n === 0 ? '—' : str_repeat('•', $n), 'bonus' => (string) $n],
                    range(0, 4),
                ),
            ]);
        }
        foreach (self::ATTRIBUTES as $key => [$label, $actions]) {
            $formula = implode(' + ', array_map(fn ($a) => "if(@{$key}.{$a}.bonus > 0, 1, 0)", array_keys($actions)));
            $this->field($t, $acc, 'valor_'.$key, $label.' (resistir)', 'computed', $pos++, 4,
                formula: $formula, roll: '{@valor_'.$key.'}d6');
        }
        $this->field($t, $acc, 'tirada', 'Estudiar', 'dice_button', $pos++, 4, roll: '{@perspicacia.estudiar.bonus}d6');

        $st = $this->section($tab, 'estres', 'Estrés y trauma', 2, columns: 2);
        $this->field($t, $st, 'estres', 'Estrés', 'track', 0, 8,
            config: ['boxes' => 9, 'shape' => 'box', 'states' => ['Marcada']], summary: true);
        $this->field($t, $st, 'trauma', 'Trauma', 'track', 1, 4,
            config: ['boxes' => 4, 'shape' => 'dot', 'states' => ['Marcada']]);
        $this->field($t, $st, 'traumas', 'Traumas', 'multiselect', 2, 12, config: [
            'max_selections' => 4,
            'options' => array_map(fn ($o) => ['value' => $o, 'label' => $o],
                ['Frío', 'Atormentado', 'Obsesivo', 'Paranoico', 'Temerario', 'Blando', 'Inestable', 'Despiadado']),
        ]);

        $dm = $this->section($tab, 'dano', 'Daño', 3, columns: 2);
        $this->field($t, $dm, 'dano_3', 'Nivel 3 — necesita ayuda', 'text', 0, 9);
        $this->field($t, $dm, 'sanacion', 'Sanación', 'clock', 1, 3, config: ['segments' => 4]);
        $this->field($t, $dm, 'dano_2a', 'Nivel 2 — −1d', 'text', 2, 6);
        $this->field($t, $dm, 'dano_2b', 'Nivel 2 — −1d', 'text', 3, 6);
        $this->field($t, $dm, 'dano_1a', 'Nivel 1 — efecto reducido', 'text', 4, 6);
        $this->field($t, $dm, 'dano_1b', 'Nivel 1 — efecto reducido', 'text', 5, 6);
        $this->field($t, $dm, 'armadura', 'Armadura', 'multiselect', 6, 12, config: ['options' => [
            ['value' => 'armadura', 'label' => 'Armadura'],
            ['value' => 'pesada', 'label' => 'Pesada'],
            ['value' => 'especial', 'label' => 'Especial'],
        ]]);

        $tab2 = $this->tab($t, 'equipo', 'Equipo y avance', 1);

        $eq = $this->section($tab2, 'carga', 'Carga y equipo', 0, columns: 2);
        $this->field($t, $eq, 'carga', 'Carga', 'select', 0, 4, config: ['allow_empty' => false, 'options' => [
            ['value' => '3', 'label' => 'Ligera (3)'], ['value' => '5', 'label' => 'Normal (5)'], ['value' => '6', 'label' => 'Pesada (6)'],
        ]], default: '5');
        $this->field($t, $eq, 'objetos', 'Objetos', 'repeater', 1, 12, config: [
            'max_rows' => 20,
            'columns' => [
                ['key' => 'objeto', 'label' => 'Objeto', 'type' => 'text'],
                ['key' => 'carga', 'label' => 'Carga', 'type' => 'number'],
                ['key' => 'llevado', 'label' => 'Llevado', 'type' => 'checkbox'],
                ['key' => 'usada', 'label' => 'Usada', 'type' => 'computed', 'formula' => 'if(@row.llevado, @row.carga, 0)'],
            ],
        ]);
        $this->field($t, $eq, 'carga_usada', 'Carga usada', 'computed', 2, 4,
            formula: 'concat(sum(@objetos[*].usada), " / ", @carga)');

        $av = $this->section($tab2, 'avance', 'Avance y dinero', 1, columns: 2);
        $this->field($t, $av, 'xp_arquetipo', 'Experiencia de arquetipo', 'track', 0, 12,
            config: ['boxes' => 8, 'shape' => 'box', 'states' => ['Marcada']]);
        foreach (self::ATTRIBUTES as $key => [$label]) {
            $this->field($t, $av, 'xp_'.$key, 'Experiencia de '.$label, 'track', 1, 4,
                config: ['boxes' => 6, 'shape' => 'box', 'states' => ['Marcada']]);
        }
        $this->field($t, $av, 'monedas', 'Monedas', 'counter', 4, 4, config: ['min' => 0, 'max' => 4]);
        $this->field($t, $av, 'alijo', 'Alijo', 'counter', 5, 4, config: ['min' => 0, 'max' => 40]);
        $this->field($t, $av, 'reloj_largo', 'Proyecto a largo plazo', 'clock', 6, 4, config: ['segments' => 8]);

        $this->publish($t, '1.0 — Fase 4');
    }
}
