<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas: la pieza de primera clase del sistema. Un "sistema de juego" es
 * una fila aquí, no código. Ver §3.2 del plan.
 *
 * Nota sobre la referencia circular: templates.current_version_id apunta a
 * template_versions, que a su vez apunta a templates. La clave foránea se añade
 * en una migración posterior, cuando ambas tablas ya existen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('slug', 180)->unique();
            $table->string('tagline', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('game_line', 120)->nullable();   // "D&D 5e", "Vampiro", "Casero"…

            $table->string('cover_image_path')->nullable();
            $table->string('icon', 32)->nullable();

            $table->string('visibility', 16)->default('private');  // private|unlisted|public
            $table->boolean('is_official')->default(false);

            $table->foreignId('forked_from_id')->nullable()
                ->constrained('templates')->nullOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();

            $table->unsignedInteger('installs_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Consulta del catálogo público, ordenado por popularidad.
            $table->index(['visibility', 'installs_count'], 'idx_templates_catalog');
            $table->index('owner_id');
        });

        Schema::create('template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');                 // 1, 2, 3…
            $table->string('label', 160)->nullable();
            $table->text('changelog')->nullable();

            // Snapshot completo e inmutable del árbol de la plantilla. Renderizar
            // una hoja lee esto y nada más: una consulta en vez de cuatro JOINs.
            $table->jsonb('compiled_schema');

            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_id', 'version']);
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->foreign('current_version_id')
                ->references('id')->on('template_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('template_versions');
        Schema::dropIfExists('templates');
    }
};
