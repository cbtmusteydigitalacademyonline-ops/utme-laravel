<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneUnverifiedUsers extends Command
{
    protected $signature = 'users:prune-unverified {--days=30 : Minimum age of the account} {--dry-run : Only count, do not delete}';

    protected $description = 'Deletes old student accounts that never verified their email, never took an exam and never paid';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));

        $query = DB::table('users')
            ->where('role', 'student')
            ->whereNull('email_verified_at')
            ->where('created_at', '<', now()->subDays($days))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('exams')->whereColumn('exams.user_id', 'users.id'))
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('payments')->whereColumn('payments.user_id', 'users.id'));

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} unused accounts older than {$days} days.");
            return self::SUCCESS;
        }

        $query->delete();
        $this->info("Deleted {$count} unused accounts older than {$days} days.");

        return self::SUCCESS;
    }
}
