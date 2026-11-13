<?php

declare(strict_types=1);

namespace Chronicle\Process;

use Chronicle\Process\Jobs\ExecuteProcess;
use Chronicle\Process\Models\ProcessInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use LogicException;

final class Scheduler
{
    public function run(int $dispatchBatchSize = 5, ?int $runUntilDispatchedAtLeast = null): void
    {
        $totalDispatches = 0;
        $noDispatchStreak = 0;

        while (true) {
            $dispatchCount = 0;
            $dispatchCount += $this->dispatchEligibleProcesses($dispatchBatchSize);
            $dispatchCount += $this->sweepExpiredExecutionClaims($dispatchBatchSize);

            if ($dispatchCount === 0) {
                $noDispatchStreak++;
            } else {
                $noDispatchStreak = 0;
            }

            $totalDispatches += $dispatchCount;

            if ($runUntilDispatchedAtLeast && $totalDispatches >= $runUntilDispatchedAtLeast) {
                return;
            }

            if ($noDispatchStreak) {
                $seconds = min(60, 2 ** min($noDispatchStreak - 1, 6));
                Sleep::for($seconds)->seconds();
            }
        }
    }

    public function dispatchEligibleProcesses(int $batchSize = 5): int
    {
        return DB::connection()->transaction(function () use ($batchSize) {
            $instances = ProcessInstance::query()
                ->where('active', true)
                ->whereNull('claim_token')
                ->where('status', 'suspended')
                ->where(function (Builder $query) {
                    $query
                        ->where(function (Builder $q) {
                            $q->whereNull('suspended_until')
                                ->whereNull('suspended_for_signals');
                        })
                        ->orWhere('suspended_until', '<=', now())
                        ->orWhereExists(function (QueryBuilder $signals): void {
                            $signalValue = match (DB::connection()->getDriverName()) {
                                'sqlite' => 'signals.step_key',
                                'pgsql' => 'to_jsonb(signals.step_key)',
                                'mysql', 'mariadb' => 'JSON_QUOTE(signals.step_key)',
                                default => throw new LogicException('Unsupported database driver'),
                            };

                            $signals->selectRaw('1')
                                ->from('process_journal_entry as signals')
                                ->whereColumn('signals.process_instance_id', 'process_instance.id')
                                ->where('signals.entry_type', 'signal')
                                ->whereNull('signals.consumed_by')
                                ->whereJsonContains(
                                    'process_instance.suspended_for_signals',
                                    DB::raw($signalValue),
                                );
                        });
                })
                ->lock('FOR UPDATE SKIP LOCKED')
                ->orderBy('id', 'asc')
                ->limit($batchSize)
                ->get();

            foreach ($instances as $instance) {
                $this->dispatchProcess($instance);
            }

            return $instances->count();
        });
    }

    public function sweepExpiredExecutionClaims(int $batchSize = 5): int
    {
        return DB::connection()->transaction(function () use ($batchSize) {
            $instances = ProcessInstance::query()
                ->where('active', true)
                ->where('status', 'running')
                ->whereNotNull('claim_token')
                ->where('claim_expires_at', '<=', now())
                ->orderBy('id', 'asc')
                ->limit($batchSize)
                ->lock('FOR UPDATE SKIP LOCKED')
                ->get();

            foreach ($instances as $instance) {
                $this->dispatchProcess($instance);
            }

            return $instances->count();
        });
    }

    private function dispatchProcess(ProcessInstance $instance): void
    {
        $instance->claim_token = null;
        $instance->claim_expires_at = null;
        $instance->status = 'pending';
        $instance->save();
        ExecuteProcess::dispatch($instance)->beforeCommit();
    }
}
