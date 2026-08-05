<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('game_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seat');
            $table->string('color')->nullable();
            $table->string('faction')->nullable();
            $table->string('homeland')->nullable();
            $table->boolean('is_ready')->default(false);
            $table->unsignedTinyInteger('result_place')->nullable();
            $table->integer('final_score')->nullable();
            $table->timestamps();

            $table->unique(['game_id', 'user_id']);
            $table->unique(['game_id', 'seat']);
            $table->unique(['game_id', 'color']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_players');
    }
};
