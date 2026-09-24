<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::table('games')->whereNotNull('active_player_id')->orderBy('id')
            ->eachById(function (object $game): void {
                $gamePlayerId = DB::table('game_players')
                    ->where('game_id', $game->id)->where('user_id', $game->active_player_id)->value('id');
                DB::table('games')->where('id', $game->id)->update(['active_game_player_id' => $gamePlayerId]);
            });

        DB::table('game_actions')->whereNotNull('player_id')->orderBy('id')
            ->eachById(function (object $action): void {
                $gamePlayerId = DB::table('game_players')
                    ->where('game_id', $action->game_id)->where('user_id', $action->player_id)->value('id');
                DB::table('game_actions')->where('id', $action->id)->update(['game_player_id' => $gamePlayerId]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('games')->update(['active_game_player_id' => null]);
        DB::table('game_actions')->update(['game_player_id' => null]);
    }
};
