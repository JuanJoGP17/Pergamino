<?php

/**
 * Ejecuta formula-cases.json contra el motor de PHP.
 * Su gemelo es run-js.mjs. Los dos DEBEN dar el mismo resultado.
 */

require __DIR__.'/../../verify-autoload.php';

use App\Domain\Formula\Evaluator;
use App\Domain\Formula\Formula;

$data = json_decode(file_get_contents(__DIR__.'/formula-cases.json'), true);
$evaluator = Evaluator::make($data['values'], $data['computed'], $data['settings']);

// Modo volcado: imprime lo que da el motor, sin juzgar. Sirve para comparar
// los dos motores entre sí (ver diff-engines.sh).
$dump = in_array('--dump', $argv, true);

$pass = 0;
$fail = 0;
$failures = [];

foreach ($data['cases'] as $case) {
    $label = "[{$case['group']}] {$case['formula']}";

    if ($dump) {
        // Los casos de sintaxis no llegan al motor de JS: se omiten para que
        // los dos volcados sean comparables línea a línea.
        if (($case['phase'] ?? '') === 'compile') {
            continue;
        }

        try {
            $value = $evaluator->evaluate($case['ast']);
            $line = ['f' => $case['formula'], 'v' => $value];
        } catch (Throwable $e) {
            $line = ['f' => $case['formula'], 'err' => true];
        }

        echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";

        continue;
    }

    // Casos que deben fallar al ANALIZAR: solo aplican a PHP, que es quien analiza.
    if (($case['phase'] ?? '') === 'compile') {
        try {
            Formula::compile($case['formula']);
            $fail++;
            $failures[] = "$label → debería no compilar, pero compiló";
        } catch (Throwable) {
            $pass++;
        }

        continue;
    }

    try {
        $actual = $evaluator->evaluate($case['ast']);

        if (! empty($case['error'])) {
            $fail++;
            $failures[] = "$label → debería lanzar error, devolvió ".json_encode($actual, JSON_UNESCAPED_UNICODE);

            continue;
        }

        if (json_encode($actual) === json_encode($case['expect'])) {
            $pass++;
        } else {
            $fail++;
            $failures[] = sprintf(
                '%s → esperado %s, obtenido %s',
                $label,
                json_encode($case['expect'], JSON_UNESCAPED_UNICODE),
                json_encode($actual, JSON_UNESCAPED_UNICODE),
            );
        }
    } catch (Throwable $e) {
        if (! empty($case['error'])) {
            $pass++;
        } else {
            $fail++;
            $failures[] = "$label → lanzó: {$e->getMessage()}";
        }
    }
}

if ($dump) {
    exit(0);   // en modo volcado no se imprime resumen: rompería el diff
}

foreach ($failures as $f) {
    echo "  FAIL $f\n";
}

echo $fail === 0
    ? "PHP  — {$pass} casos OK\n"
    : "PHP  — {$pass} ok, {$fail} FALLOS\n";

exit($fail === 0 ? 0 : 1);
