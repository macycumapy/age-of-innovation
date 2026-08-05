<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('lobby')->index();
            $table->unsignedTinyInteger('round')->default(1);
            $table->string('phase')->default('setup');
            $table->foreignId('active_player_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('version')->default(0);
            $table->json('state');
            $table->string('rules_version')->default('1.2');
            $table->string('random_seed');
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
