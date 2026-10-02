<?php

namespace App\Domain\Schema;

/**
 * Grafo de dependencias entre campos y su orden topológico (algoritmo de Kahn).
 *
 * Clase pura: no toca Eloquent, ni la base de datos, ni Laravel. Eso es
 * deliberado — es la pieza con más aristas del compilador y quiero poder
 * probarla aislada.
 *
 * La Fase 2 la reutiliza tal cual: el evaluador de fórmulas recorre este mismo
 * orden, y quien construya el grafo pasará las referencias sacadas del AST en
 * lugar de las del escáner léxico. La clase no se entera de la diferencia.
 */
final class DependencyGraph
{
    /** @var array<string,array<int,string>> nodo → dependencias */
    private array $deps = [];

    /** @param array<string,array<int,string>> $deps nodo → claves de las que depende */
    public function __construct(array $deps = [])
    {
        foreach ($deps as $node => $requires) {
            $this->add((string) $node, $requires);
        }
    }

    /** @param array<int,string> $requires */
    public function add(string $node, array $requires = []): self
    {
        $this->deps[$node] ??= [];

        foreach ($requires as $dep) {
            // Las auto-referencias y los duplicados no aportan orden; filtrarlos
            // aquí evita que el conteo de grados de entrada quede descuadrado.
            if ($dep !== $node && ! in_array($dep, $this->deps[$node], true)) {
                $this->deps[$node][] = $dep;
            }
        }

        return $this;
    }

    public function nodes(): array
    {
        return array_keys($this->deps);
    }

    /**
     * Orden en el que evaluar los nodos: cada uno aparece después de todas sus
     * dependencias.
     *
     * Las dependencias hacia nodos que no están en el grafo se ignoran: son
     * hojas ya resueltas (un campo normal del que depende un calculado), no
     * imponen orden.
     *
     * El desempate es alfabético, así que el mismo árbol produce siempre el
     * mismo compiled_schema — importante para poder comparar versiones.
     *
     * @return array<int,string>
     *
     * @throws SchemaCompilationException si existe un ciclo
     */
    public function topologicalOrder(): array
    {
        $inDegree = [];
        $dependents = [];

        foreach ($this->deps as $node => $requires) {
            $inDegree[$node] ??= 0;

            foreach ($requires as $dep) {
                if (! array_key_exists($dep, $this->deps)) {
                    continue;   // hoja externa al grafo
                }

                $dependents[$dep][] = $node;
                $inDegree[$node]++;
            }
        }

        $queue = array_keys(array_filter($inDegree, fn (int $n) => $n === 0));
        sort($queue);

        $order = [];

        while ($queue !== []) {
            $node = array_shift($queue);
            $order[] = $node;

            $freed = [];
            foreach ($dependents[$node] ?? [] as $child) {
                if (--$inDegree[$child] === 0) {
                    $freed[] = $child;
                }
            }

            if ($freed !== []) {
                sort($freed);
                $queue = array_merge($queue, $freed);
                sort($queue);
            }
        }

        if (count($order) !== count($this->deps)) {
            throw SchemaCompilationException::cycle($this->findCycle($order));
        }

        return $order;
    }

    /**
     * Reconstruye un ciclo concreto para poder nombrarlo en el mensaje de error.
     * "fuerza → ca → fuerza" es accionable; "hay un ciclo" no lo es.
     *
     * @param  array<int,string>  $resolved  nodos que sí se pudieron ordenar
     * @return array<int,string>
     */
    private function findCycle(array $resolved): array
    {
        $stuck = array_values(array_diff(array_keys($this->deps), $resolved));

        if ($stuck === []) {
            return [];
        }

        $path = [];
        $onPath = [];
        $node = $stuck[0];

        // Camina siguiendo dependencias atascadas hasta repetir un nodo: ese
        // punto de repetición cierra el ciclo.
        while ($node !== null && ! isset($onPath[$node])) {
            $path[] = $node;
            $onPath[$node] = count($path) - 1;

            $node = null;
            foreach ($this->deps[$path[count($path) - 1]] as $dep) {
                if (in_array($dep, $stuck, true)) {
                    $node = $dep;
                    break;
                }
            }
        }

        if ($node === null) {
            return $path;
        }

        // Recortar la "cola" que lleva hasta el ciclo y cerrarlo.
        $cycle = array_slice($path, $onPath[$node]);
        $cycle[] = $node;

        return $cycle;
    }
}
