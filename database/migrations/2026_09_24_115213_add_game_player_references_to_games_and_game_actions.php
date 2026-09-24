<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('game_players', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });
        Schema::table('games', function (Blueprint $table): void {
            $table->foreignId('active_game_player_id')->nullable()->after('active_player_id')
                ->constrained('game_players')->nullOnDelete();
        });
        Schema::table('game_actions', function (Blueprint $table): void {
            $table->foreignId('game_player_id')->nullable()->after('player_id')
                ->constrained('game_players')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('game_player_id');
        });
        Schema::table('games', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_game_player_id');
        });
        Schema::table('game_players', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
