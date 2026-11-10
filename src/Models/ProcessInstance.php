<?php

declare(strict_types=1);

namespace Chronicle\Process\Models;

use Carbon\Carbon;
use Database\Factories\ProcessInstanceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $process_type
 * @property ?string $process_version
 * @property string $key
 * @property bool $active
 * @property string $status
 * @property ?string $claim_token
 * @property ?Carbon $claim_expires_at
 * @property ?Carbon $suspended_until
 * @property array<mixed> $suspended_for_signals
 * @property array<string, mixed> $document
 * @property ?array<string, mixed> $result
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<ProcessInstance> query()
 */
class ProcessInstance extends Model
{
    /** @use HasFactory<ProcessInstanceFactory> */
    use HasFactory;

    use HasUlids;

    protected $table = 'process_instance';

    protected $guarded = [];

    protected static function newFactory(): ProcessInstanceFactory
    {
        return ProcessInstanceFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'claim_expires_at' => 'datetime',
            'suspended_until' => 'datetime',
            'document' => 'array',
            'result' => 'array',
            'suspended_for_signals' => 'array',
        ];
    }
}
