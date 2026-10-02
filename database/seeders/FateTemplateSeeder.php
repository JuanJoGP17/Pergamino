<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\BuildsTemplates;
use Illuminate\Database\Seeder;

/**
 * FATE Básico como plantilla (entregable de la Fase 4: el catálogo de campos
 * cubre FATE).
 *
 * Lo que lo hace FATE y no d20:
 *   - Habilidades con la escalera (Mediocre +0 … Soberbio +5): una lista
 *     derivada cuyos «niveles» son los peldaños.
 *   - Estrés físico y mental como marcas, con más casillas según Físico y
 *     Voluntad: el número de casillas es una fórmula.
 *   - Aspectos como etiquetas, consecuencias como texto, puntos de destino
 *     con su recuperación.
 */
class FateTemplateSeeder extends Seeder
{
    use BuildsTemplates;

    private const SKILLS = [
        'atletismo' => 'Atletismo', 'robo' => 'Robo', 'contactos' => 'Contactos',
        'artesania' => 'Artesanía', 'enganar' => 'Engañar', 'conducir' => 'Conducir',
        'empatia' => 'Empatía', 'pelear' => 'Pelear', 'investigar' => 'Investigar',
        'saber' => 'Saber', 'percepcion' => 'Percepción', 'fisico' => 'Físico',
        'provocar' => 'Provocar', 'empatizar' => 'Simpatía', 'recursos' => 'Recursos',
        'disparar' => 'Disparar', 'sigilo' => 'Sigilo', 'voluntad' => 'Voluntad',
    ];

    private const LADDER = [
        ['key' => 'mediocre', 'label' => 'Mediocre', 'bonus' => '0'],
        ['key' => 'normal', 'label' => 'Normal (+1)', 'bonus' => '1'],
        ['key' => 'bueno', 'label' => 'Bueno (+2)', 'bonus' => '2'],
        ['key' => 'grande', 'label' => 'Grande (+3)', 'bonus' => '3'],
        ['key' => 'excelente', 'label' => 'Excelente (+4)', 'bonus' => '4'],
        ['key' => 'soberbio', 'label' => 'Soberbio (+5)', 'bonus' => '5'],
    ];

    public function run(): void
    {
        $t = $this->newTemplate('fate-basico', [
            'name' => 'FATE Básico',
            'tagline' => 'Aspectos, escalera de habilidades, estrés y consecuencias',
            'game_line' => 'FATE',
            'settings' => [],
        ]);

        if (! $t) {
            return;
        }

        $tab = $this->tab($t, 'personaje', 'Personaje', 0, default: true);

        $a = $this->section($tab, 'aspectos', 'Aspectos', 0, columns: 2);
        $this->field($t, $a, 'nombre_personaje', 'Nombre', 'text', 0, 6);
        $this->field($t, $a, 'retrato', 'Retrato', 'portrait', 1, 6, config: ['shape' => 'circle']);
        $this->field($t, $a, 'concepto', 'Concepto principal', 'text', 2, 6, summary: true);
        $this->field($t, $a, 'complicacion', 'Complicación', 'text', 3, 6);
        $this->field($t, $a, 'aspectos', 'Otros aspectos', 'tags', 4, 12,
            help: 'Escribe cada aspecto y pulsa Enter.');

        $h = $this->section($tab, 'habilidades', 'Habilidades', 1);
        $this->field($t, $h, 'habilidades', 'Habilidades', 'derived_list', 0, 8, config: [
            'items' => array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(self::SKILLS), self::SKILLS),
            'levels' => self::LADDER,
        ], help: 'La pirámide: una Grande, dos Buenas, tres Normales.');
        $this->field($t, $h, 'tirada', 'Tirar Atletismo', 'dice_button', 1, 4,
            roll: '4dF + {@habilidades.atletismo.bonus}');

        $e = $this->section($tab, 'estres', 'Estrés y consecuencias', 2, columns: 2);
        // FATE Básico: Físico/Voluntad Normal o Bueno dan una casilla más; Grande
        // o más, dos.
        $this->field($t, $e, 'estres_fisico', 'Estrés físico', 'track', 0, 6, config: [
            'boxes_formula' => '2 + if(@habilidades.fisico.bonus >= 1, 1, 0) + if(@habilidades.fisico.bonus >= 3, 1, 0)',
            'shape' => 'box', 'states' => ['Marcada'],
        ], summary: true);
        $this->field($t, $e, 'estres_mental', 'Estrés mental', 'track', 1, 6, config: [
            'boxes_formula' => '2 + if(@habilidades.voluntad.bonus >= 1, 1, 0) + if(@habilidades.voluntad.bonus >= 3, 1, 0)',
            'shape' => 'box', 'states' => ['Marcada'],
        ]);
        $this->field($t, $e, 'consecuencia_leve', 'Leve (2)', 'text', 2, 4);
        $this->field($t, $e, 'consecuencia_moderada', 'Moderada (4)', 'text', 3, 4);
        $this->field($t, $e, 'consecuencia_grave', 'Grave (6)', 'text', 4, 4);

        $p = $this->section($tab, 'proezas', 'Proezas y destino', 3, columns: 2);
        $this->field($t, $p, 'proezas', 'Proezas', 'repeater', 0, 12, config: [
            'max_rows' => 10,
            'columns' => [
                ['key' => 'nombre', 'label' => 'Proeza', 'type' => 'text'],
                ['key' => 'efecto', 'label' => 'Efecto', 'type' => 'text'],
            ],
        ]);
        $this->field($t, $p, 'recuperacion', 'Recuperación', 'number', 1, 4, config: ['min' => 1], default: 3);
        $this->field($t, $p, 'puntos_destino', 'Puntos de destino', 'counter', 2, 4,
            config: ['min' => 0, 'max' => 10], default: 3, summary: true,
            help: 'Al empezar la sesión, súbelos hasta tu recuperación.');

        $this->publish($t, '1.0 — Fase 4');
    }
}
