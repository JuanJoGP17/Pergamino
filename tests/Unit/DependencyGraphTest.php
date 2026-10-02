<?php

use App\Domain\Schema\DependencyGraph;
use App\Domain\Schema\FormulaReferences;
use App\Domain\Schema\SchemaCompilationException;

it('ordena una cadena de dependencias', function () {
    $order = (new DependencyGraph([
        'ca' => ['destreza_mod'],
        'destreza_mod' => ['destreza'],
        'destreza' => [],
    ]))->topologicalOrder();

    expect($order)->toBe(['destreza', 'destreza_mod', 'ca']);
});

it('ignora dependencias que no son nodos del grafo', function () {
    // @destreza es un campo normal, ya tiene valor: no impone orden.
    expect((new DependencyGraph(['iniciativa' => ['destreza']]))->topologicalOrder())
        ->toBe(['iniciativa']);
});

it('resuelve un rombo poniendo la raíz primero y la hoja al final', function () {
    $order = (new DependencyGraph([
        'd' => ['b', 'c'],
        'b' => ['a'],
        'c' => ['a'],
        'a' => [],
    ]))->topologicalOrder();

    expect($order[0])->toBe('a')
        ->and($order[3])->toBe('d');
});

it('no considera ciclo una auto-referencia', function () {
    expect((new DependencyGraph(['a' => ['a']]))->topologicalOrder())->toBe(['a']);
});

it('rechaza un ciclo y nombra los campos implicados', function () {
    expect(fn () => (new DependencyGraph(['fuerza' => ['ca'], 'ca' => ['fuerza']]))->topologicalOrder())
        ->toThrow(SchemaCompilationException::class);

    try {
        (new DependencyGraph(['fuerza' => ['ca'], 'ca' => ['fuerza']]))->topologicalOrder();
    } catch (SchemaCompilationException $e) {
        expect($e->getMessage())->toContain('fuerza')->toContain('ca');
    }
});

it('detecta ciclos indirectos', function () {
    expect(fn () => (new DependencyGraph(['a' => ['b'], 'b' => ['c'], 'c' => ['a']]))->topologicalOrder())
        ->toThrow(SchemaCompilationException::class);
});

it('produce siempre el mismo orden para el mismo grafo', function () {
    $build = fn () => (new DependencyGraph([
        'x' => ['a'], 'y' => ['a'], 'z' => ['x', 'y'], 'a' => [],
    ]))->topologicalOrder();

    expect($build())->toBe($build());
});

describe('FormulaReferences', function () {
    it('extrae referencias quitando la propiedad derivada', function () {
        expect(FormulaReferences::extract('@fuerza.mod + @destreza.mod'))
            ->toBe(['fuerza', 'destreza']);
    });

    it('no confunde una arroba dentro de una cadena con una referencia', function () {
        expect(FormulaReferences::extract('if(@clase == "mago@torre", 1, 0)'))
            ->toBe(['clase']);
    });

    it('resuelve el alias @self al campo real, nunca a «self»', function () {
        // @self.nivel depende de «nivel»: si no contara como arista, un campo
        // que lo usara podría evaluarse antes que el propio nivel.
        expect(FormulaReferences::extract('@self.nivel + @fuerza'))->toBe(['nivel', 'fuerza']);
    });

    it('reúne las referencias de todas las fórmulas del campo', function () {
        expect(FormulaReferences::forField([
            'formula' => '@a + 1',
            'visible_if' => '@b > 0',
            'roll_expression' => '1d20 + {@c}',
        ]))->toBe(['a', 'b', 'c']);
    });
});
