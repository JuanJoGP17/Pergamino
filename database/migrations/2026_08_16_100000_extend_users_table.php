<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('display_name')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('timezone', 64)->default('America/Bogota');
            $table->string('locale', 8)->default('es');
            $table->jsonb('settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['display_name', 'avatar_path', 'timezone', 'locale', 'settings']);
        });
    }
};
