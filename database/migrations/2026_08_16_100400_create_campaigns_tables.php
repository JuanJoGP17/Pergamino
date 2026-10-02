<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mesas de rol (§3.2 y §8). Colaborativas por polling, sin WebSockets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid()->unique();
            $table->foreignId('gm_id')->constrained('users')->cascadeOnDelete();

            $table->string('name', 160);
            $table->string('join_code', 16)->unique();      // "AURORA-7421"
            $table->text('description')->nullable();
            $table->string('banner_path')->nullable();

            $table->foreignId('default_template_id')->nullable()
                ->constrained('templates')->nullOnDelete();

            $table->jsonb('settings')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->index('gm_id');
        });

        Schema::create('campaign_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('player');   // gm|player|spectator
            $table->string('nickname', 80)->nullable();
            $table->timestamp('joined_at')->nullable();

            $table->unique(['campaign_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('campaign_sheet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sheet_id')->constrained()->cascadeOnDelete();
            $table->string('share_level', 16)->default('summary'); // full|summary|hidden
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['campaign_id', 'sheet_id']);
        });

        Schema::create('campaign_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160)->nullable();
            $table->text('body')->nullable();          // markdown
            $table->boolean('is_gm_only')->default(false);
            $table->timestamps();

            $table->index('campaign_id');
        });

        Schema::create('dice_rolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('sheet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('label', 180)->nullable();
            $table->string('expression', 120);
            $table->jsonb('result');
            $table->string('mode', 16)->default('normal');  // normal|advantage|disadvantage
            $table->boolean('is_private')->default(false);
            $table->timestamp('created_at')->nullable();

            // El log de mesa hace polling con: campaign_id = ? AND id > ?
            $table->index(['campaign_id', 'id']);
            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dice_rolls');
        Schema::dropIfExists('campaign_notes');
        Schema::dropIfExists('campaign_sheet');
        Schema::dropIfExists('campaign_members');
        Schema::dropIfExists('campaigns');
    }
};
