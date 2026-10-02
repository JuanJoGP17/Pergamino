<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Temas (§6.3, Fase 5): la cascada plantilla → mesa → hoja. La hoja ya tenía
 * `theme_override`; faltaban la plantilla y la mesa.
 *
 * El tema de la plantilla se lee en vivo, no desde la versión publicada: es
 * apariencia, no estructura, y cambiarlo no debe obligar a publicar ni a
 * migrar hojas. La versión guarda una copia para la exportación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->jsonb('theme')->nullable();
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->jsonb('theme_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('theme_override');
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
