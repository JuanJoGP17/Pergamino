<?php

use App\Domain\Builder\BuilderException;
use App\Domain\Builder\TemplateEditor;
use App\Domain\Schema\PublishTemplate;
use App\Domain\Schema\SchemaValidator;
use App\Models\TemplateField;
use App\Models\TemplateSection;
use App\Models\User;

/**
 * Las operaciones del constructor visual (Fase 3), sin interfaz.
 *
 * Los objetos JSON (config) se comparan con toEqual y no con toBe: jsonb de
 * PostgreSQL no conserva el orden de las claves, y en CI estos tests también
 * corren contra PostgreSQL. Las listas (opciones, posiciones) sí lo conservan.
 */
beforeEach(function () {
    $this->editor = new TemplateEditor;
    $this->user = User::factory()->create();
    $this->template = $this->editor->create($this->user, 'Mi sistema');
    $this->tab = $this->template->tabs()->first();
    $this->section = $this->tab->sections()->first();
});

/** Claves de los campos de una sección, en orden. */
function keysIn(TemplateSection $section): array
{
    return $section->fields()->pluck('key')->all();
}

// ------------------------------------------------------------- creación

it('crea una plantilla en blanco con una pestaña y una sección', function () {
    expect($this->template->owner_id)->toBe($this->user->id)
        ->and($this->template->slug)->toBe('mi-sistema')
        ->and($this->template->visibility)->toBe('private')
        ->and($this->tab->is_default)->toBeTrue()
        ->and($this->section->label)->toBe('Principal')
        ->and($this->template->isPublished())->toBeFalse();
});

// --------------------------------------------------------------- campos

it('añade campos con claves únicas sacadas de la etiqueta', function () {
    $this->editor->addField($this->template, $this->section->id, 'number');
    $this->editor->addField($this->template, $this->section->id, 'number');
    $this->editor->addField($this->template, $this->section->id, 'text', label: 'Puntos de Vida máx.');

    expect(keysIn($this->section))->toBe(['numero', 'numero_2', 'puntos_de_vida_max']);
});

it('respeta los números de la etiqueta al generar la clave', function () {
    $this->editor->addFieldsInBulk($this->template, $this->section->id, 'number', "Conjuros nivel 1\nConjuros nivel 2");

    expect(keysIn($this->section))->toBe(['conjuros_nivel_1', 'conjuros_nivel_2']);
});

it('da a cada tipo una configuración y un ancho de partida', function () {
    $attr = $this->editor->addField($this->template, $this->section->id, 'attribute');
    $calc = $this->editor->addField($this->template, $this->section->id, 'computed');
    $list = $this->editor->addField($this->template, $this->section->id, 'select');

    expect($attr->col_span)->toBe(2)
        ->and($attr->config['show_mod'])->toBeTrue()
        ->and($calc->formula)->toBe('0')
        ->and($list->config['options'])->toHaveCount(2);
});

it('no deja añadir tipos que aún no existen', function () {
    expect(fn () => $this->editor->addField($this->template, $this->section->id, 'repeater'))
        ->toThrow(BuilderException::class);
});

it('añade en bloque las 18 habilidades de 5e de una vez', function () {
    $names = implode("\n", ['Acrobacias', 'Arcanos', 'Atletismo', 'Engaño', 'Historia', 'Interpretación',
        'Intimidación', 'Investigación', 'Juego de manos', 'Medicina', 'Naturaleza', 'Percepción',
        'Perspicacia', 'Persuasión', 'Religión', 'Sigilo', 'Supervivencia', 'Trato con animales']);

    $fields = $this->editor->addFieldsInBulk($this->template, $this->section->id, 'computed', $names."\n\n");

    expect($fields)->toHaveCount(18)
        ->and($fields[3]->key)->toBe('engano')
        ->and($fields[17]->key)->toBe('trato_con_animales');
});

it('actualiza un campo saneando cada propiedad', function () {
    $field = $this->editor->addField($this->template, $this->section->id, 'number');

    $field = $this->editor->updateField($this->template, $field->id, [
        'label' => '  Nivel ',
        'key' => 'Nivel del PJ',
        'col_span' => 40,
        'config' => ['min' => '1', 'max' => '20', 'inventado' => 'x', 'suffix' => ''],
        'default_value' => '1',
        'visible_if' => '   ',
        'is_summary' => '1',
    ]);

    expect($field->label)->toBe('Nivel')
        ->and($field->key)->toBe('nivel_del_pj')
        ->and($field->col_span)->toBe(12)
        ->and($field->config)->toEqual(['min' => 1, 'max' => 20])
        ->and($field->default_value)->toBe(['value' => 1])
        ->and($field->visible_if)->toBeNull()
        ->and($field->is_summary)->toBeTrue();
});

it('no deja repetir una clave ni usar una palabra reservada', function () {
    $a = $this->editor->addField($this->template, $this->section->id, 'number', label: 'Fuerza');
    $b = $this->editor->addField($this->template, $this->section->id, 'number', label: 'Destreza');

    expect(fn () => $this->editor->updateField($this->template, $b->id, ['key' => 'fuerza']))
        ->toThrow(BuilderException::class, 'únicas en toda la plantilla')
        ->and(fn () => $this->editor->updateField($this->template, $a->id, ['key' => 'self']))
        ->toThrow(BuilderException::class, 'reservada');
});

it('lee las opciones de una lista escritas una por línea', function () {
    $field = $this->editor->addField($this->template, $this->section->id, 'select');

    $field = $this->editor->updateField($this->template, $field->id, [
        'config' => ['options' => "Mago\nGuerrero | Guerrera\n\nMago"],
    ]);

    expect($field->config['options'])->toEqual([
        ['value' => 'Mago', 'label' => 'Mago'],
        ['value' => 'Guerrero', 'label' => 'Guerrera'],
    ]);
});

it('al cambiar de tipo descarta la configuración del anterior', function () {
    $field = $this->editor->addField($this->template, $this->section->id, 'select');
    $field = $this->editor->updateField($this->template, $field->id, ['type' => 'computed']);

    expect($field->type)->toBe('computed')
        ->and($field->config)->toBeNull()
        ->and($field->formula)->toBe('0');
});

it('duplica un campo justo debajo, con clave nueva', function () {
    $a = $this->editor->addField($this->template, $this->section->id, 'attribute', label: 'Fuerza');
    $this->editor->addField($this->template, $this->section->id, 'attribute', label: 'Destreza');

    $copy = $this->editor->duplicateField($this->template, $a->id);
    $again = $this->editor->duplicateField($this->template, $copy->id);

    expect(keysIn($this->section))->toBe(['fuerza', 'fuerza_2', 'fuerza_3', 'destreza'])
        ->and($copy->label)->toBe('Fuerza (copia)')
        ->and($again->key)->toBe('fuerza_3');
});

// ----------------------------------------------------------- movimiento

it('reordena campos dentro de una sección', function () {
    foreach (['a', 'b', 'c', 'd'] as $l) {
        $fields[$l] = $this->editor->addField($this->template, $this->section->id, 'number', label: $l);
    }

    $this->editor->moveField($this->template, $fields['d']->id, $this->section->id, 0);
    expect(keysIn($this->section))->toBe(['d', 'a', 'b', 'c']);

    $this->editor->moveField($this->template, $fields['d']->id, $this->section->id, 99);
    expect(keysIn($this->section))->toBe(['a', 'b', 'c', 'd']);
});

it('mueve un campo a otra sección de otra pestaña sin dejar huecos', function () {
    $otherTab = $this->editor->addTab($this->template, 'Combate');
    $other = $this->editor->addSection($this->template, $otherTab->id, 'Ataque');
    $a = $this->editor->addField($this->template, $this->section->id, 'number', label: 'a');
    $this->editor->addField($this->template, $this->section->id, 'number', label: 'b');
    $this->editor->addField($this->template, $other->id, 'number', label: 'x');

    $this->editor->moveField($this->template, $a->id, $other->id, 1);

    expect(keysIn($this->section))->toBe(['b'])
        ->and($this->section->fields()->pluck('position')->all())->toBe([0])
        ->and(keysIn($other))->toBe(['x', 'a']);
});

it('reordena pestañas y mueve secciones entre ellas', function () {
    $b = $this->editor->addTab($this->template, 'B');
    $c = $this->editor->addTab($this->template, 'C');

    $this->editor->moveTab($this->template, $c->id, 0);
    expect($this->template->tabs()->pluck('label')->all())->toBe(['C', 'General', 'B']);

    $s = $this->editor->addSection($this->template, $b->id, 'Suelta');
    $this->editor->moveSection($this->template, $s->id, $this->tab->id, 0);

    expect($this->tab->sections()->pluck('label')->all())->toBe(['Suelta', 'Principal'])
        ->and($b->sections()->count())->toBe(0);
});

// --------------------------------------------------------------- borrar

it('borra en cascada y no deja quedarse sin pestañas', function () {
    $tab = $this->editor->addTab($this->template, 'Sobra');
    $section = $this->editor->addSection($this->template, $tab->id);
    $this->editor->addField($this->template, $section->id, 'text');

    $this->editor->deleteTab($this->template, $tab->id);

    expect(TemplateSection::find($section->id))->toBeNull()
        ->and(TemplateField::where('template_section_id', $section->id)->count())->toBe(0)
        ->and(fn () => $this->editor->deleteTab($this->template, $this->tab->id))
        ->toThrow(BuilderException::class, 'al menos una pestaña');
});

// ----------------------------------------------------------- seguridad

it('rechaza ids que pertenecen a otra plantilla', function () {
    $ajena = $this->editor->create(User::factory()->create(), 'Ajena');
    $suSeccion = $ajena->tabs()->first()->sections()->first();
    $suCampo = $this->editor->addField($ajena, $suSeccion->id, 'text');

    expect(fn () => $this->editor->addField($this->template, $suSeccion->id, 'text'))->toThrow(BuilderException::class)
        ->and(fn () => $this->editor->updateField($this->template, $suCampo->id, ['label' => 'mío']))->toThrow(BuilderException::class)
        ->and(fn () => $this->editor->moveField($this->template, $suCampo->id, $this->section->id, 0))->toThrow(BuilderException::class)
        ->and(fn () => $this->editor->deleteTab($this->template, $ajena->tabs()->first()->id))->toThrow(BuilderException::class);

    expect($suCampo->fresh()->label)->not->toBe('mío');
});

// -------------------------------------------------------------- ajustes

it('guarda los ajustes y las tablas de consulta', function () {
    $template = $this->editor->updateSettings($this->template, [
        'name' => 'Vampiro casero',
        'visibility' => 'unlisted',
        'mod_base' => '0',
        'lookups' => '{"clanes": {"Brujah": "Celeridad", "Ventrue": "Dominación"}}',
    ]);

    expect($template->name)->toBe('Vampiro casero')
        ->and($template->visibility)->toBe('unlisted')
        ->and($template->settings['mod_base'])->toBe(0)
        ->and($template->settings['lookups']['clanes']['Ventrue'])->toBe('Dominación');

    expect(fn () => $this->editor->updateSettings($this->template, ['lookups' => '[1, 2]']))
        ->toThrow(BuilderException::class)
        ->and(fn () => $this->editor->updateSettings($this->template, ['lookups' => '{roto']))
        ->toThrow(BuilderException::class);
});

// ------------------------------------------------- borrador y publicación

it('sabe si hay cambios sin publicar', function () {
    $this->editor->addField($this->template, $this->section->id, 'number', label: 'Nivel');
    expect(TemplateEditor::hasUnpublishedChanges($this->template->fresh()))->toBeTrue();

    $this->travel(1)->seconds();
    app(PublishTemplate::class)($this->template->fresh(), $this->user);
    expect(TemplateEditor::hasUnpublishedChanges($this->template->fresh()))->toBeFalse();

    $this->travel(1)->seconds();
    $this->editor->addField($this->template, $this->section->id, 'text');
    expect(TemplateEditor::hasUnpublishedChanges($this->template->fresh()))->toBeTrue();
});

it('construye un sistema completo sin escribir código y lo publica', function () {
    $s = $this->section->id;
    $nivel = $this->editor->addField($this->template, $s, 'number', label: 'Nivel');
    $vigor = $this->editor->addField($this->template, $s, 'attribute', label: 'Vigor');
    $pv = $this->editor->addField($this->template, $s, 'computed', label: 'PV');

    $this->editor->updateField($this->template, $vigor->id, ['config' => ['mod_formula' => 'floor(@self / 3)']]);
    $this->editor->updateField($this->template, $pv->id, ['formula' => '10 + @vigor.mod * @nivel']);

    expect((new SchemaValidator)->validate($this->template->fresh()))
        ->each->not->toMatchArray(['level' => 'error']);

    $version = app(PublishTemplate::class)($this->template->fresh(), $this->user);

    expect($version->compiled_schema['compute_order'])->toBe(['vigor', 'pv']);
});
