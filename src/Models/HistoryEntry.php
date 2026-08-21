<?php

declare(strict_types=1);

namespace Chronicle\Process\Models;

use Carbon\Carbon;
use Chronicle\Process\Database\Factories\HistoryEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $process_instance_id
 * @property string $label
 * @property array<string, mixed> $payload
 * @property bool $out_of_band
 * @property Carbon $recorded_at
 */
class HistoryEntry extends Model
{
    /** @use HasFactory<HistoryEntryFactory> */
    use HasFactory;

    protected $table = 'process_instance_history_entry';

    public $timestamps = false;

    protected $guarded = [];

    protected $fillable = ['process_instance_id', 'label', 'payload', 'out_of_band'];

    protected static function newFactory(): HistoryEntryFactory
    {
        return HistoryEntryFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'out_of_band' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->recorded_at)) {
                $model->recorded_at = Carbon::now();
            }
        });
    }
}
