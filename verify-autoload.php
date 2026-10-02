<?php

/**
 * Autoloader mínimo para ejecutar el dominio sin Composer ni Laravel.
 *
 * El sandbox donde se escribió este código no alcanza Packagist, así que las
 * piezas puras (fórmulas, grafo de dependencias, cálculo de modificadores) se
 * verifican con PHP a secas. En la aplicación real, Composer se encarga.
 */
spl_autoload_register(function (string $class) {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $path = __DIR__.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';

    if (is_file($path)) {
        require_once $path;
    }
});
