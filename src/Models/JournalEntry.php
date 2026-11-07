<?php

declare(strict_types=1);

namespace Chronicle\Process\Models;

use Carbon\Carbon;
use Chronicle\Process\Enums\EntryType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $process_instance_id
 * @property string $description
 * @property ?array<string, mixed> $payload
 * @property ?string $payload_type
 * @property EntryType $entry_type
 * @property Carbon $recorded_at
 * @property string $step_key
 * @property ?int $sequence
 */
class JournalEntry extends Model
{
    use HasUlids;

    public const CREATED_AT = 'recorded_at';

    public const UPDATED_AT = null;

    protected $table = 'process_journal_entry';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'recorded_at' => 'datetime',
            'entry_type' => EntryType::class,
        ];
    }
}
