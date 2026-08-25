<?php

namespace Chronicle\Process\Models;

use Carbon\Carbon;
use Database\Factories\ProcessInstanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $key
 * @property ?Carbon $eligible_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ProcessInstance extends Model
{
    /** @use HasFactory<\Database\Factories\ProcessInstanceFactory> */
    use HasFactory;

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
            'eligible_at' => 'datetime',
        ];
    }
}
