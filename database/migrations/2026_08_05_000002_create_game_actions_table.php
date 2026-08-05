<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('game_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->foreignId('player_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->json('payload');
            $table->json('events')->nullable();
            $table->unsignedBigInteger('state_version_before');
            $table->unsignedBigInteger('state_version_after');
            $table->timestamps();

            $table->unique(['game_id', 'sequence']);
            $table->index(['game_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_actions');
    }
};
