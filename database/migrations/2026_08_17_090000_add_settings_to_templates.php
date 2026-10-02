<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajustes de plantilla que consume el motor de fórmulas: la base y el divisor
 * de mod(), la progresión de prof(), y las tablas de lookup().
 *
 * Viven en la plantilla y no en la aplicación porque un sistema de juego que no
 * sea d20 querrá otros: mod() en un sistema con atributos de 1 a 5 no es
 * floor((v-10)/2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->jsonb('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
