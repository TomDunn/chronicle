<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_instance', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('process_type');
            $table->string('process_version')->nullable(true);
            $table->string('key');
            $table->boolean('active')->index(true);
            $table->enum('status', ['pending', 'suspended', 'running', 'failed', 'completed']);
            $table->uuid('claim_token')->nullable(true);
            $table->timestamp('claim_expires_at')->nullable(true);
            $table->jsonb('document');
            $table->timestamps();
            $table->unique(['name', 'key']);
        });

        if (str_contains(strtolower(DB::connection()->getDriverName()), 'db2')) {
            DB::statement('
                CREATE UNIQUE INDEX jobs_active_key_unique
                ON jobs ("key")
                WHERE active = 1;
            ');
        } else {
            DB::statement('
                CREATE UNIQUE INDEX process_active_key_unique
                ON process_instance ("key")
                WHERE active = true
            ');
        }

        Schema::create('process_instance_history_entry', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('process_instance_id')
                ->index(true)
                ->nullable(false)
                ->constrained('process_instance', 'id');
            // operator friendly label for the entry
            $table->string('label');
            // the payload
            $table->jsonb('payload')->nullable(true);
            // the payload type - used to help with deserialization
            $table->string('payload_type')->nullable(true);
            // what type of history entry is this?
            // activity, wait, signal_wait, signal
            $table->string('entry_type', 25)->nullable(false);
            // $table->foreignId('consumes_entry_id')
            //     ->index(true)
            //     ->nullable(true)
            //     ->constrained('process_instance_history_entry', 'id');
            $table->timestamp('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('process_instance_history_entry');
        DB::statement('DROP INDEX process_instance_active_key_unique');
        Schema::dropIfExists('process_instance');
        Schema::dropIfExists('process_name_key');
    }
};
