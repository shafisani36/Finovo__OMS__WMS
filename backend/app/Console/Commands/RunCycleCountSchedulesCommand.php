<?php

namespace App\Console\Commands;

use App\Models\CycleCountSchedule;
use App\Services\CycleCountService;
use Illuminate\Console\Command;

/**
 * Starts the scheduled cycle counts that are due, then moves each schedule to its next date.
 */
class RunCycleCountSchedulesCommand extends Command
{
    protected $signature = 'cycle-counts:run-schedules';

    protected $description = 'Start the scheduled cycle counts that are due today';

    public function handle(CycleCountService $counts): int
    {
        $started = 0;

        CycleCountSchedule::with('warehouse')->whereDate('next_run_on', '<=', today())->each(function (CycleCountSchedule $schedule) use ($counts, &$started) {
            if (! $schedule->warehouse?->is_active) {
                return;
            }

            $count = $counts->start($schedule->warehouse, $schedule->title, $schedule->scope_type, $schedule->scope_value, "Scheduled every {$schedule->every_days} days.", $schedule->created_by_user_id);
            // A schedule that was missed for a while starts once, then keeps its rhythm from today.
            $next = $schedule->next_run_on->copy();
            while ($next->lte(today())) {
                $next->addDays($schedule->every_days);
            }
            $schedule->update(['next_run_on' => $next, 'last_cycle_count_id' => $count->id]);
            $started++;
        });

        $this->info("{$started} scheduled cycle count(s) started.");

        return self::SUCCESS;
    }
}
