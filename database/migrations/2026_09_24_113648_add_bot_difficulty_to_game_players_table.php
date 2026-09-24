<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('game_players', function (Blueprint $table): void {
            $table->string('bot_difficulty')->nullable()->after('is_ready');
        });
    }

    public function down(): void
    {
        Schema::table('game_players', function (Blueprint $table): void {
            $table->dropColumn('bot_difficulty');
        });
    }
};
