<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\BuildsTemplates;
use Illuminate\Database\Seeder;

/**
 * Vampiro: La Mascarada (5.ª edición) como plantilla (entregable de la
 * Fase 4: el catálogo de campos cubre Vampiro).
 *
 * Lo que lo hace Vampiro y no d20:
 *   - Atributos en puntos (•••○○): marcas con forma de punto. El valor del
 *     atributo es @fuerza.marked.
 *   - Salud = Resistencia + 3 y Voluntad = Compostura + Resolución, con
 *     daño superficial (╱) y agravado (✕): marcas de dos estados cuyo número
 *     de casillas es una fórmula.
 *   - Habilidades de 0 a 5 puntos, Hambre, Humanidad y Potencia de sangre.
 *   - Reservas de dados: la tirada suma puntos y lanza tantos d10.
 */
class VampiroTemplateSeeder extends Seeder
{
    use BuildsTemplates;

    private const ATTRIBUTES = [
        'Físicos' => ['fuerza' => 'Fuerza', 'destreza' => 'Destreza', 'resistencia' => 'Resistencia'],
        'Sociales' => ['carisma' => 'Carisma', 'manipulacion' => 'Manipulación', 'compostura' => 'Compostura'],
        'Mentales' => ['inteligencia' => 'Inteligencia', 'astucia' => 'Astucia', 'resolucion' => 'Resolución'],
    ];

    private const SKILLS = [
        'armas_cc' => 'Armas cuerpo a cuerpo', 'atletismo' => 'Atletismo', 'conducir' => 'Conducir',
        'armas_fuego' => 'Armas de fuego', 'latrocinio' => 'Latrocinio', 'pelea' => 'Pelea',
        'oficios' => 'Oficios', 'sigilo' => 'Sigilo', 'supervivencia' => 'Supervivencia',
        'callejeo' => 'Callejeo', 'etiqueta' => 'Etiqueta', 'interpretacion' => 'Interpretación',
        'intimidacion' => 'Intimidación', 'liderazgo' => 'Liderazgo', 'perspicacia' => 'Perspicacia',
        'persuasion' => 'Persuasión', 'subterfugio' => 'Subterfugio', 'trato_animales' => 'Trato con animales',
        'academicismo' => 'Academicismo', 'ciencia' => 'Ciencia', 'consciencia' => 'Consciencia',
        'finanzas' => 'Finanzas', 'investigacion' => 'Investigación', 'medicina' => 'Medicina',
        'ocultismo' => 'Ocultismo', 'politica' => 'Política', 'tecnologia' => 'Tecnología',
    ];

    private const CLANS = [
        'Brujah', 'Gangrel', 'Malkavian', 'Nosferatu', 'Toreador', 'Tremere', 'Ventrue',
        'Banu Haqim', 'Hecata', 'Lasombra', 'Ministerio', 'Ravnos', 'Salubri', 'Tzimisce', 'Caitiff', 'Sangre débil',
    ];

    public function run(): void
    {
        $t = $this->newTemplate('vampiro-v5', [
            'name' => 'Vampiro: La Mascarada (V5)',
            'tagline' => 'Atributos en puntos, Salud y Voluntad con daño superficial y agravado',
            'game_line' => 'Vampiro',
            'settings' => [],
        ]);

        if (! $t) {
            return;
        }

        // ------------------------------------------------------ pestaña 1
        $tab = $this->tab($t, 'personaje', 'Personaje', 0, default: true);

        $id = $this->section($tab, 'identidad', 'Identidad', 0, columns: 2);
        $this->field($t, $id, 'retrato', 'Retrato', 'portrait', 0, 3, config: ['shape' => 'square']);
        $this->field($t, $id, 'nombre_personaje', 'Nombre', 'text', 1, 5);
        $this->field($t, $id, 'clan', 'Clan', 'select', 2, 4, config: [
            'allow_empty' => true,
            'options' => array_map(fn ($c) => ['value' => $c, 'label' => $c], self::CLANS),
        ], summary: true);
        $this->field($t, $id, 'generacion', 'Generación', 'number', 3, 3, config: ['min' => 4, 'max' => 16], default: 13);
        $this->field($t, $id, 'depredador', 'Tipo de depredador', 'text', 4, 3);
        $this->field($t, $id, 'ambicion', 'Ambición', 'text', 5, 6);
        $this->field($t, $id, 'deseo', 'Deseo', 'text', 6, 6);

        $pos = 0;
        $at = $this->section($tab, 'atributos', 'Atributos', 1, columns: 3);
        foreach (self::ATTRIBUTES as $group => $attributes) {
            $this->field($t, $at, 'grupo_'.$pos, $group, 'heading', $pos++, 12);
            foreach ($attributes as $key => $label) {
                $this->field($t, $at, $key, $label, 'track', $pos++, 4,
                    config: ['boxes' => 5, 'shape' => 'dot', 'states' => ['Punto']], default: [1]);
            }
        }

        $hab = $this->section($tab, 'habilidades', 'Habilidades', 2);
        $this->field($t, $hab, 'habilidades', 'Habilidades', 'derived_list', 0, 8, config: [
            'items' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(self::SKILLS), self::SKILLS),
            'levels' => array_map(
                fn (int $n) => ['key' => "p{$n}", 'label' => $n === 0 ? '—' : str_repeat('•', $n), 'bonus' => (string) $n],
                range(0, 5),
            ),
        ]);
        // Reserva de dados: atributo + habilidad, en d10.
        $this->field($t, $hab, 'reserva_pelea', 'Pelea (Fuerza + Pelea)', 'dice_button', 1, 4,
            roll: '{@fuerza.marked + @habilidades.pelea.bonus}d10');
        $this->field($t, $hab, 'reserva_sigilo', 'Sigilo (Destreza + Sigilo)', 'dice_button', 2, 4,
            roll: '{@destreza.marked + @habilidades.sigilo.bonus}d10');

        // ------------------------------------------------------ pestaña 2
        $tab2 = $this->tab($t, 'condicion', 'Salud y sangre', 1);

        $sv = $this->section($tab2, 'salud', 'Salud y Voluntad', 0, columns: 2);
        $this->field($t, $sv, 'salud', 'Salud', 'track', 0, 6, config: [
            'boxes_formula' => '@resistencia.marked + 3', 'shape' => 'box', 'states' => ['Superficial', 'Agravado'],
        ], summary: true);
        $this->field($t, $sv, 'voluntad', 'Voluntad', 'track', 1, 6, config: [
            'boxes_formula' => '@compostura.marked + @resolucion.marked', 'shape' => 'box', 'states' => ['Superficial', 'Agravado'],
        ]);
        $this->field($t, $sv, 'deteriorado', 'Estado', 'computed', 2, 12,
            formula: 'if(@salud.marked >= @salud.boxes, "Deteriorado: −2 dados en tiradas físicas", "")',
            visibleIf: '@salud.marked >= @salud.boxes');

        $sa = $this->section($tab2, 'sangre', 'La Bestia', 1, columns: 2);
        $this->field($t, $sa, 'hambre', 'Hambre', 'track', 0, 6,
            config: ['boxes' => 5, 'shape' => 'dot', 'states' => ['Hambre']], default: [1], summary: true);
        $this->field($t, $sa, 'humanidad', 'Humanidad', 'counter', 1, 3, config: ['min' => 0, 'max' => 10], default: 7);
        $this->field($t, $sa, 'potencia_sangre', 'Potencia de sangre', 'counter', 2, 3, config: ['min' => 0, 'max' => 10], default: 1);
        $this->field($t, $sa, 'manchas', 'Manchas', 'track', 3, 12,
            config: ['boxes' => 10, 'shape' => 'pip', 'states' => ['Mancha']]);

        $di = $this->section($tab2, 'disciplinas', 'Disciplinas', 2);
        $this->field($t, $di, 'disciplinas', 'Disciplinas', 'repeater', 0, 12, config: [
            'max_rows' => 12,
            'columns' => [
                ['key' => 'disciplina', 'label' => 'Disciplina', 'type' => 'select', 'options' => [
                    'Animalismo', 'Auspex', 'Celeridad', 'Dominación', 'Fortaleza', 'Hechicería de Sangre',
                    'Ofuscación', 'Oblivion', 'Potencia', 'Presencia', 'Protean', 'Alquimia de Sangre Débil',
                ]],
                ['key' => 'nivel', 'label' => 'Nivel', 'type' => 'number'],
                ['key' => 'poderes', 'label' => 'Poderes', 'type' => 'text'],
            ],
        ]);

        $this->field($t, $di, 'experiencia', 'Experiencia', 'resource', 1, 6,
            config: ['show_temp' => false, 'allow_overflow' => false],
            help: 'Actual = sin gastar; máximo = total ganado.');

        $this->publish($t, '1.0 — Fase 4');
    }
}
