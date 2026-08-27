<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('process_name_key', function (Blueprint $table) {
            $table->string('name');
            $table->string('key');
            $table->unique(['name', 'key']);
        });

        Schema::create('process_instance', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('key');
            $table->timestamp('eligible_at')->nullable(true);
            $table->timestamps();
            $table->unique(['name', 'key']);
        });

        Schema::create('process_instance_history_entry', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_instance_id')
                ->index(true)
                ->nullable(false)
                ->constrained('process_instance', 'id');
            $table->string('label');
            $table->jsonb('payload')->nullable(true);
            $table->string('entry_type', 25)->nullable(false);
            $table->boolean('out_of_band')->default(false);
            $table->timestamp('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_instance_history_entry');
        Schema::dropIfExists('process_instance');
        Schema::dropIfExists('process_name_key');
    }
};
