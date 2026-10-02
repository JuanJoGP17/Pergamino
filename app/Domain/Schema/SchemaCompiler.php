<?php

namespace App\Domain\Schema;

use App\Domain\Formula\Ast;
use App\Domain\Formula\Formula;
use App\Domain\Formula\Interpolator;
use App\Models\Template;

/**
 * Aplana el árbol normalizado de una plantilla (tabs → sections → fields) en el
 * snapshot que se guarda en template_versions.compiled_schema.
 *
 * Dos responsabilidades:
 *   1. Aplanar. Renderizar una hoja debe costar una lectura, no cuatro JOINs.
 *   2. Ordenar los cálculos. Los campos calculados se resuelven en orden
 *      topológico, calculado UNA vez aquí en vez de en cada render.
 *
 * Los ciclos se rechazan al publicar, no al renderizar: una hoja publicada
 * nunca puede entrar en un bucle infinito de cálculo.
 */
final class SchemaCompiler
{
    /**
     * @throws SchemaCompilationException si el árbol tiene ciclos o referencias rotas
     */
    public function compile(Template $template): CompiledSchema
    {
        $issues = (new SchemaValidator)->validate($template);
        $errors = array_filter($issues, fn (array $i) => $i['level'] === 'error');

        if ($errors !== []) {
            throw SchemaCompilationException::withIssues(array_values($errors));
        }

        $template->loadMissing('tabs.sections.fields');

        $fields = [];
        $tabs = [];
        $summary = [];

        foreach ($template->tabs as $tab) {
            $sections = [];

            foreach ($tab->sections as $section) {
                $keys = [];

                foreach ($section->fields as $field) {
                    $fields[$field->key] = $this->compileField($field);
                    $keys[] = $field->key;

                    if ($field->is_summary) {
                        $summary[] = $field->key;
                    }
                }

                $sections[] = [
                    'key' => $section->key,
                    'label' => $section->label,
                    'description' => $section->description,
                    'columns' => (int) $section->columns,
                    'collapsible' => (bool) $section->collapsible,
                    'collapsed_default' => (bool) $section->collapsed_default,
                    'style' => $section->style ?? [],
                    'visible_if' => $section->visible_if,
                    'visible_ast' => Formula::compile($section->visible_if),
                    'field_keys' => $keys,
                ];
            }

            $tabs[] = [
                'key' => $tab->key,
                'label' => $tab->label,
                'icon' => $tab->icon,
                'is_default' => (bool) $tab->is_default,
                'sections' => $sections,
            ];
        }

        return CompiledSchema::fromArray([
            'schema_version' => CompiledSchema::VERSION,
            'template' => [
                'name' => $template->name,
                'game_line' => $template->game_line,
                'slug' => $template->slug,
            ],
            'theme' => [],   // lo llena el editor de apariencia en la Fase 5

            // Configuración que consumen mod(), prof() y lookup(). Viaja dentro
            // del esquema para que el navegador evalúe con los mismos ajustes
            // que el servidor.
            'settings' => $template->settings ?? [],
            'tabs' => $tabs,
            'fields' => $fields,
            'compute_order' => $this->computeOrder($fields),
            'summary_fields' => $summary,
        ]);
    }

    /**
     * Un campo compilado lleva su AST ya construido.
     *
     * Ese es el mecanismo que impide que los motores de PHP y JS diverjan: el
     * navegador no analiza fórmulas, recibe exactamente el mismo árbol que
     * evaluó el servidor. Ver app/Domain/Formula/README.md.
     */
    private function compileField($field): array
    {
        $key = $field->key;
        $config = $field->config ?? [];

        // `@self` a secas es el valor del propio campo (ver Ast::bindSelf).
        $compile = fn (?string $source) => Ast::bindSelf(Formula::compile($source), $key);

        $roll = Interpolator::compile($field->roll_expression);
        if ($roll !== null) {
            $roll['holes'] = array_map(fn (array $hole) => Ast::bindSelf($hole, $key), $roll['holes']);
        }

        return [
            'key' => $field->key,
            'label' => $field->label,
            'help_text' => $field->help_text,
            'type' => $field->type,
            'col_span' => (int) $field->col_span,
            'config' => $config,
            'default_value' => $field->default_value['value'] ?? null,

            // Texto original: lo necesita el constructor para poder editarlo.
            'formula' => $field->formula,
            'roll_expression' => $field->roll_expression,
            'visible_if' => $field->visible_if,
            'readonly_if' => $field->readonly_if,

            // Árboles ya analizados: lo que se evalúa en tiempo de ejecución.
            'ast' => $compile($field->formula),
            'visible_ast' => $compile($field->visible_if),
            'readonly_ast' => $compile($field->readonly_if),
            'roll' => $roll,

            // Solo los `attribute`: el modificador deja de ser floor((v-10)/2)
            // fijo y pasa a ser la fórmula que defina la plantilla.
            'mod_ast' => $field->type === FieldType::Attribute->value
                ? $compile($config['mod_formula'] ?? null)
                : null,

            'is_required' => (bool) $field->is_required,
            'is_summary' => (bool) $field->is_summary,
        ];
    }

    /**
     * Orden en el que hay que evaluar los campos calculados.
     *
     * Solo entran los que tienen alguna fórmula: los demás ya traen su valor
     * puesto por el usuario y no hay nada que calcular. El resultado se recorre
     * una vez por render, O(n), sin recursión ni detección de ciclos en
     * caliente: eso ya se resolvió aquí, al publicar.
     *
     * @param  array<string,array>  $fields
     * @return array<int,string>
     *
     * @throws SchemaCompilationException si hay un ciclo
     */
    private function computeOrder(array $fields): array
    {
        $refs = [];

        foreach ($fields as $key => $field) {
            $fieldRefs = FormulaReferences::forField($field);

            if ($fieldRefs !== [] || ! empty($field['ast']) || ! empty($field['mod_ast'])) {
                $refs[$key] = $fieldRefs;
            }
        }

        return (new DependencyGraph($refs))->topologicalOrder();
    }
}
