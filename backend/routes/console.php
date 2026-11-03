<?php

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

// Every job runs on one server only, even when several app servers run the scheduler behind a load balancer
// (needs a shared cache: Redis or the database).

// Keep store products and orders current (needs the scheduler cron and a queue worker on the server)
Schedule::command('stores:sync')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// New and changed store orders, every minute (stores that notify Finovo send them at once as well)
Schedule::command('stores:import-orders')->everyMinute()->withoutOverlapping()->onOneServer();

// Send stock and shipment updates to stores, and retry failed ones (also sent right after each change)
Schedule::command('stores:push-updates')->everyMinute()->withoutOverlapping()->onOneServer();

// Courier status of parcels on their way; delivered parcels are marked delivered
Schedule::command('couriers:track')->everyThirtyMinutes()->withoutOverlapping()->onOneServer();

// Scheduled cycle counts start early in the morning.
Schedule::command('cycle-counts:run-schedules')->dailyAt('05:00')->onOneServer();

// Recurring orders that are due (each run places at most one order per plan)
Schedule::command('orders:recurring')->hourly()->withoutOverlapping()->onOneServer();

// Expired API tokens are deleted once a day.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();

// Saved answers to offline scans are kept for a week.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily()->onOneServer();
