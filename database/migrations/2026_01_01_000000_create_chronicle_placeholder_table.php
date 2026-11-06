<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('process_instance', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('process_type');
            $table->string('process_version')->nullable(true);
            $table->string('key')->nullable(true);
            $table->boolean('active')->index(true);
            $table->enum('status', ['pending', 'suspended', 'running', 'failed', 'completed']);
            $table->uuid('claim_token')->nullable(true);
            $table->timestamp('claim_expires_at')->nullable(true);
            $table->jsonb('document');
            $table->timestamps();
        });

        if (Str::contains(DB::connection()->getDriverName(), ['db2', 'sqlite'])) {
            // DB::statement("CREATE UNIQUE INDEX process_instance_active_key_unique ON process_instance(key) WHERE active = 'true';");
            DB::statement('
                CREATE UNIQUE INDEX process_instance_active_key_unique
                ON process_instance (process_type, key)
                WHERE active = 1 AND key IS NOT NULL;
            ');
        } else {
            DB::statement('
                CREATE UNIQUE INDEX process_instance_active_key_unique
                ON process_instance ("key")
                WHERE active = true;
            ');
        }

        Schema::create('process_journal_entry', function (Blueprint $table) {
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
            // these come from the payload, which may be from
            // an internal, framework defined payload or a from
            // the application using the framework
            $table->string('payload_type')->nullable(true);
            // what type of history entry is this?
            // activity, wait, signal_wait, signal.
            // entry types are strictly defined by the framework.
            $table->string('entry_type', 25)->nullable(false);
            // $table->foreignId('consumes_entry_id')
            //     ->index(true)
            //     ->nullable(true)
            //     ->constrained('process_instance_history_entry', 'id');
            $table->timestamp('recorded_at');
        });

        DB::statement('
            CREATE UNIQUE INDEX process_journal_entry_process_launch_unique
            ON process_journal_entry (process_instance_id, entry_type)
            WHERE entry_type = \'launch\';
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX process_journal_entry_process_launch_unique');
        Schema::dropIfExists('process_journal_entry');
        DB::statement('DROP INDEX process_instance_active_key_unique');
        Schema::dropIfExists('process_instance');
        Schema::dropIfExists('process_name_key');
    }
};
