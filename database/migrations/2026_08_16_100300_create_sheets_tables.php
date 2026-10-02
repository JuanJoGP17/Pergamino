<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hojas de personaje (§3.2).
 *
 * Cada hoja queda anclada a una template_version concreta e inmutable. Editar
 * la plantilla nunca rompe hojas ya creadas: is_template_dirty avisa de que hay
 * una versión más nueva y el usuario decide cuándo migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheets', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->restrictOnDelete();
            $table->foreignId('template_version_id')->constrained('template_versions')->restrictOnDelete();

            $table->string('name', 160);
            $table->string('portrait_path')->nullable();
            $table->string('banner_path')->nullable();

            $table->jsonb('data');                      // valores introducidos por el usuario
            $table->jsonb('computed')->nullable();      // caché de campos calculados
            $table->jsonb('theme_override')->nullable();

            $table->string('visibility', 16)->default('private'); // private|campaign|unlisted|public
            $table->boolean('is_template_dirty')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'updated_at']);
            $table->index('template_id');
        });

        // Buscar DENTRO del documento (§3.1): sheets.data @> '{"nivel": 5}'.
        // jsonb_path_ops ocupa menos y basta para @>, que es lo que usará el
        // catálogo. Solo PostgreSQL lo entiende; los tests corren en SQLite.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX sheets_data_gin ON sheets USING GIN (data jsonb_path_ops)');
        }

        Schema::create('sheet_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('data');
            $table->jsonb('computed')->nullable();
            $table->string('summary', 255)->nullable();   // "PV 32 → 18"
            $table->timestamp('created_at')->nullable();

            // El scheduler poda por hoja quedándose con las 30 últimas.
            $table->index(['sheet_id', 'created_at']);
        });

        Schema::create('sheet_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token', 64)->nullable()->unique();   // enlace público
            $table->string('ability', 16)->default('view');       // view|edit
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['sheet_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_shares');
        Schema::dropIfExists('sheet_revisions');
        Schema::dropIfExists('sheets');
    }
};
