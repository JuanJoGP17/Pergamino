<?php

namespace App\Domain\Builder;

/**
 * Bloques prefabricados del constructor (§6.2, punto 4): una sección entera,
 * con sus campos y fórmulas, insertada de un clic. Es lo que hace usable el
 * constructor para quien no quiere empezar de cero.
 *
 * Cada bloque es autocontenido salvo donde lo dice su descripción («usa
 *
 * @destreza»): si la plantilla no tiene ese campo, el panel de validación lo
 * señala. Al insertar, las claves que ya existan se renombran (fuerza →
 * fuerza_2) y las fórmulas DEL BLOQUE se reescriben para seguir apuntando a
 * sus campos. Ver TemplateEditor::insertBlock().
 *
 * La configuración se escribe aquí estructurada y pasa por FieldConfig::clean
 * al insertar, igual que lo que llega del inspector.
 */
final class Prefabs
{
    /** @return array<string,array{label:string,description:string,section:string,fields:array}> */
    public static function all(): array
    {
        $attribute = fn (string $key, string $label) => [
            'key' => $key, 'label' => $label, 'type' => 'attribute', 'col_span' => 2,
            'config' => ['min' => 1, 'max' => 30, 'show_mod' => true],
            'default_value' => 10,
            'roll_expression' => '1d20 + {@'.$key.'.mod}',
        ];

        $skill = fn (string $key, string $label, string $attr) => ['key' => $key, 'label' => $label, 'base' => "@{$attr}.mod"];

        return [
            'atributos_d20' => [
                'label' => '6 atributos estilo d20',
                'description' => 'Fuerza, Destreza, Constitución, Inteligencia, Sabiduría y Carisma, con su modificador.',
                'section' => 'Atributos',
                'fields' => [
                    $attribute('fuerza', 'Fuerza'),
                    $attribute('destreza', 'Destreza'),
                    $attribute('constitucion', 'Constitución'),
                    $attribute('inteligencia', 'Inteligencia'),
                    $attribute('sabiduria', 'Sabiduría'),
                    $attribute('carisma', 'Carisma'),
                ],
            ],

            'nivel_d20' => [
                'label' => 'Nivel, experiencia y competencia',
                'description' => 'Nivel calculado con los umbrales de experiencia de 5e y el bono de competencia que da.',
                'section' => 'Progreso',
                'fields' => [
                    ['key' => 'experiencia', 'label' => 'Experiencia', 'type' => 'progress', 'col_span' => 6,
                        'config' => ['thresholds' => [0, 300, 900, 2700, 6500, 14000, 23000, 34000, 48000, 64000,
                            85000, 100000, 120000, 140000, 165000, 195000, 225000, 265000, 305000, 355000]]],
                    ['key' => 'nivel', 'label' => 'Nivel', 'type' => 'computed', 'col_span' => 3,
                        'formula' => 'max(1, @experiencia.level)', 'config' => ['format' => 'int']],
                    ['key' => 'competencia', 'label' => 'Bono de competencia', 'type' => 'computed', 'col_span' => 3,
                        'formula' => 'prof(@nivel)', 'config' => ['format' => 'mod']],
                ],
            ],

            'combate_d20' => [
                'label' => 'PV, CA e iniciativa',
                'description' => 'Puntos de golpe con descanso largo, clase de armadura e iniciativa. Usa @destreza.',
                'section' => 'Combate',
                'fields' => [
                    ['key' => 'pv', 'label' => 'Puntos de golpe', 'type' => 'resource', 'col_span' => 6,
                        'config' => ['show_temp' => true, 'reset_on' => 'long']],
                    ['key' => 'armadura', 'label' => 'Bono de armadura', 'type' => 'number', 'col_span' => 2],
                    ['key' => 'ca', 'label' => 'Clase de armadura', 'type' => 'computed', 'col_span' => 2,
                        'formula' => '10 + @destreza.mod + @armadura', 'config' => ['format' => 'int']],
                    ['key' => 'iniciativa', 'label' => 'Iniciativa', 'type' => 'computed', 'col_span' => 2,
                        'formula' => '@destreza.mod', 'config' => ['format' => 'mod'],
                        'roll_expression' => '1d20 + {@iniciativa}'],
                ],
            ],

            'habilidades_5e' => [
                'label' => 'Las 18 habilidades de 5e',
                'description' => 'Lista derivada con competencia y pericia. Usa los 6 atributos y @competencia.',
                'section' => 'Habilidades',
                'fields' => [
                    ['key' => 'habilidades', 'label' => 'Habilidades', 'type' => 'derived_list', 'col_span' => 12,
                        'config' => [
                            'items' => [
                                $skill('acrobacias', 'Acrobacias', 'destreza'),
                                $skill('arcanos', 'Arcanos', 'inteligencia'),
                                $skill('atletismo', 'Atletismo', 'fuerza'),
                                $skill('engano', 'Engaño', 'carisma'),
                                $skill('historia', 'Historia', 'inteligencia'),
                                $skill('interpretacion', 'Interpretación', 'carisma'),
                                $skill('intimidacion', 'Intimidación', 'carisma'),
                                $skill('investigacion', 'Investigación', 'inteligencia'),
                                $skill('juego_de_manos', 'Juego de manos', 'destreza'),
                                $skill('medicina', 'Medicina', 'sabiduria'),
                                $skill('naturaleza', 'Naturaleza', 'inteligencia'),
                                $skill('percepcion', 'Percepción', 'sabiduria'),
                                $skill('perspicacia', 'Perspicacia', 'sabiduria'),
                                $skill('persuasion', 'Persuasión', 'carisma'),
                                $skill('religion', 'Religión', 'inteligencia'),
                                $skill('sigilo', 'Sigilo', 'destreza'),
                                $skill('supervivencia', 'Supervivencia', 'sabiduria'),
                                $skill('trato_con_animales', 'Trato con animales', 'sabiduria'),
                            ],
                            'levels' => [
                                ['key' => 'no', 'label' => '—', 'bonus' => '0'],
                                ['key' => 'comp', 'label' => 'Competente', 'bonus' => '@competencia'],
                                ['key' => 'pericia', 'label' => 'Pericia', 'bonus' => '@competencia * 2'],
                            ],
                        ]],
                    ['key' => 'percepcion_pasiva', 'label' => 'Percepción pasiva', 'type' => 'computed', 'col_span' => 4,
                        'formula' => '10 + @habilidades.percepcion.bonus', 'config' => ['format' => 'int']],
                ],
            ],

            'conjuros' => [
                'label' => 'Tabla de conjuros por nivel',
                'description' => 'Conjuros con nivel, escuela y si están preparados.',
                'section' => 'Conjuros',
                'fields' => [
                    ['key' => 'conjuros', 'label' => 'Conjuros', 'type' => 'repeater', 'col_span' => 12,
                        'config' => ['max_rows' => 100, 'columns' => [
                            ['key' => 'nivel', 'label' => 'Nivel', 'type' => 'number'],
                            ['key' => 'nombre', 'label' => 'Conjuro', 'type' => 'text'],
                            ['key' => 'escuela', 'label' => 'Escuela', 'type' => 'select', 'options' => [
                                'Abjuración', 'Adivinación', 'Conjuración', 'Encantamiento',
                                'Evocación', 'Ilusión', 'Nigromancia', 'Transmutación',
                            ]],
                            ['key' => 'preparado', 'label' => 'Preparado', 'type' => 'checkbox'],
                        ]]],
                    ['key' => 'conjuros_preparados', 'label' => 'Preparados', 'type' => 'computed', 'col_span' => 3,
                        'formula' => 'sum(@conjuros[*].preparado)'],
                ],
            ],

            'inventario' => [
                'label' => 'Inventario con peso',
                'description' => 'Objetos con cantidad y peso, la carga total y una bolsa de monedas.',
                'section' => 'Equipo',
                'fields' => [
                    ['key' => 'inventario', 'label' => 'Inventario', 'type' => 'repeater', 'col_span' => 12,
                        'config' => ['max_rows' => 100, 'columns' => [
                            ['key' => 'objeto', 'label' => 'Objeto', 'type' => 'text'],
                            ['key' => 'cantidad', 'label' => 'Cant.', 'type' => 'number'],
                            ['key' => 'peso', 'label' => 'Peso', 'type' => 'number'],
                            ['key' => 'total', 'label' => 'Peso total', 'type' => 'computed', 'formula' => '@row.peso * @row.cantidad'],
                        ]]],
                    ['key' => 'carga', 'label' => 'Carga', 'type' => 'computed', 'col_span' => 3,
                        'formula' => 'sum(@inventario[*].total)'],
                    ['key' => 'bolsa', 'label' => 'Monedas', 'type' => 'currency', 'col_span' => 9,
                        'config' => ['denominations' => [
                            ['key' => 'pc', 'label' => 'Cobre', 'rate' => 1],
                            ['key' => 'pp', 'label' => 'Plata', 'rate' => 10],
                            ['key' => 'pe', 'label' => 'Electro', 'rate' => 50],
                            ['key' => 'po', 'label' => 'Oro', 'rate' => 100],
                            ['key' => 'ppt', 'label' => 'Platino', 'rate' => 1000],
                        ]]],
                ],
            ],

            'reloj' => [
                'label' => 'Reloj de progreso',
                'description' => 'Un reloj de 4 segmentos con lo que mide (Blades in the Dark).',
                'section' => 'Relojes',
                'fields' => [
                    ['key' => 'reloj_objetivo', 'label' => 'Qué mide', 'type' => 'text', 'col_span' => 9],
                    ['key' => 'reloj', 'label' => 'Reloj', 'type' => 'clock', 'col_span' => 3, 'config' => ['segments' => 4]],
                ],
            ],

            'estres_fate' => [
                'label' => 'Estrés y consecuencias (FATE)',
                'description' => 'Aspectos, estrés físico y mental, y las tres consecuencias.',
                'section' => 'Estrés',
                'fields' => [
                    ['key' => 'aspectos', 'label' => 'Aspectos', 'type' => 'tags', 'col_span' => 12],
                    ['key' => 'estres_fisico', 'label' => 'Estrés físico', 'type' => 'track', 'col_span' => 6,
                        'config' => ['boxes' => 3, 'shape' => 'box', 'states' => ['Marcada']]],
                    ['key' => 'estres_mental', 'label' => 'Estrés mental', 'type' => 'track', 'col_span' => 6,
                        'config' => ['boxes' => 3, 'shape' => 'box', 'states' => ['Marcada']]],
                    ['key' => 'consecuencia_leve', 'label' => 'Consecuencia leve (2)', 'type' => 'text', 'col_span' => 4],
                    ['key' => 'consecuencia_moderada', 'label' => 'Consecuencia moderada (4)', 'type' => 'text', 'col_span' => 4],
                    ['key' => 'consecuencia_grave', 'label' => 'Consecuencia grave (6)', 'type' => 'text', 'col_span' => 4],
                    ['key' => 'puntos_destino', 'label' => 'Puntos de destino', 'type' => 'counter', 'col_span' => 4,
                        'config' => ['min' => 0, 'max' => 5]],
                ],
            ],

            'salud_vampiro' => [
                'label' => 'Salud y Voluntad (Vampiro)',
                'description' => 'Salud = Resistencia + 3 y Voluntad = Compostura + Resolución, con daño superficial y agravado.',
                'section' => 'Salud y Voluntad',
                'fields' => [
                    ['key' => 'resistencia', 'label' => 'Resistencia', 'type' => 'number', 'col_span' => 4,
                        'config' => ['min' => 1, 'max' => 5], 'default_value' => 1],
                    ['key' => 'compostura', 'label' => 'Compostura', 'type' => 'number', 'col_span' => 4,
                        'config' => ['min' => 1, 'max' => 5], 'default_value' => 1],
                    ['key' => 'resolucion', 'label' => 'Resolución', 'type' => 'number', 'col_span' => 4,
                        'config' => ['min' => 1, 'max' => 5], 'default_value' => 1],
                    ['key' => 'salud', 'label' => 'Salud', 'type' => 'track', 'col_span' => 6,
                        'config' => ['boxes_formula' => '@resistencia + 3', 'shape' => 'box', 'states' => ['Superficial', 'Agravado']]],
                    ['key' => 'voluntad', 'label' => 'Voluntad', 'type' => 'track', 'col_span' => 6,
                        'config' => ['boxes_formula' => '@compostura + @resolucion', 'shape' => 'box', 'states' => ['Superficial', 'Agravado']]],
                    ['key' => 'hambre', 'label' => 'Hambre', 'type' => 'track', 'col_span' => 6,
                        'config' => ['boxes' => 5, 'shape' => 'dot', 'states' => ['Hambre']]],
                    ['key' => 'humanidad', 'label' => 'Humanidad', 'type' => 'counter', 'col_span' => 6,
                        'config' => ['min' => 0, 'max' => 10], 'default_value' => 7],
                ],
            ],

            'estres_blades' => [
                'label' => 'Estrés y trauma (Blades)',
                'description' => '9 casillas de estrés, 4 de trauma, armadura y un reloj de sanación.',
                'section' => 'Estrés y trauma',
                'fields' => [
                    ['key' => 'estres', 'label' => 'Estrés', 'type' => 'track', 'col_span' => 8,
                        'config' => ['boxes' => 9, 'shape' => 'box', 'states' => ['Marcada']]],
                    ['key' => 'trauma', 'label' => 'Trauma', 'type' => 'track', 'col_span' => 4,
                        'config' => ['boxes' => 4, 'shape' => 'dot', 'states' => ['Marcada']]],
                    ['key' => 'traumas', 'label' => 'Traumas', 'type' => 'multiselect', 'col_span' => 8,
                        'config' => ['options' => array_map(fn ($t) => ['value' => $t, 'label' => $t],
                            ['Frío', 'Atormentado', 'Obsesivo', 'Paranoico', 'Temerario', 'Blando', 'Inestable', 'Despiadado'])]],
                    ['key' => 'sanacion', 'label' => 'Sanación', 'type' => 'clock', 'col_span' => 4, 'config' => ['segments' => 4]],
                ],
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
