<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estructura de autoría: lo que edita el constructor visual (§3.2).
 *
 * Se guarda normalizado para poder editar por partes sin reescribir un blob
 * gigante en cada pulsación. Al publicar, SchemaCompiler lo aplana en
 * template_versions.compiled_schema, que es lo que se lee al renderizar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label', 120);
            $table->string('icon', 32)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['template_id', 'key']);
            $table->index(['template_id', 'position']);
        });

        Schema::create('template_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_tab_id')->constrained()->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('label', 160)->nullable();
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedTinyInteger('columns')->default(1);      // 1..4
            $table->boolean('collapsible')->default(false);
            $table->boolean('collapsed_default')->default(false);
            $table->jsonb('style')->nullable();
            $table->text('visible_if')->nullable();                   // fórmula booleana
            $table->timestamps();

            $table->index(['template_tab_id', 'position']);
        });

        Schema::create('template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_section_id')->constrained()->cascadeOnDelete();

            // Denormalizado a propósito: las fórmulas referencian los campos por
            // clave sin prefijo (@fuerza), así que la clave debe ser única en
            // TODA la plantilla, no solo dentro de su sección.
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();

            $table->string('key', 64);
            $table->string('label', 160);
            $table->string('help_text', 255)->nullable();
            $table->string('type', 32);

            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedTinyInteger('col_span')->default(12);    // rejilla de 12

            $table->jsonb('config')->nullable();
            $table->jsonb('default_value')->nullable();

            $table->text('formula')->nullable();            // campos calculados
            $table->text('roll_expression')->nullable();    // "1d20 + {@destreza.mod}"
            $table->text('visible_if')->nullable();
            $table->text('readonly_if')->nullable();

            $table->boolean('is_required')->default(false);
            $table->boolean('is_summary')->default(false);  // sale en la tarjeta de mesa
            $table->timestamps();

            $table->unique(['template_id', 'key']);
            $table->index(['template_section_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_fields');
        Schema::dropIfExists('template_sections');
        Schema::dropIfExists('template_tabs');
    }
};
