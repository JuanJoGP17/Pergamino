<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reportes de plantillas del catálogo (§16 del plan: «contenido con derechos
 * en el catálogo público → aviso en la publicación y sistema de reportes»).
 * Uno por persona y plantilla; si vuelve a reportar, se actualiza el motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 32);          // copyright | offensive | spam | other
            $table->text('details')->nullable();
            $table->timestamps();

            $table->unique(['template_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_reports');
    }
};
