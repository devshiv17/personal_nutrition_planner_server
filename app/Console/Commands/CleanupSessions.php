<?php

namespace App\Console\Commands;

use App\Services\SessionManagementService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sessions:cleanup 
                            {--dry-run : Show what would be cleaned without actually doing it}
                            {--force : Force cleanup without confirmation}
                            {--days=30 : Number of days to keep inactive sessions}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired and inactive user sessions';

    /**
     * Execute the console command.
     */
    public function handle(SessionManagementService $sessionService): int
    {
        $this->info('Starting session cleanup...');
        
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $days = (int) $this->option('days');
        
        if (!$force && !$dryRun) {
            if (!$this->confirm('This will permanently clean up expired sessions. Continue?')) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $cutoffDate = Carbon::now()->subDays($days);
        
        // Count sessions to be cleaned
        $expiredCount = $this->countExpiredSessions();
        $inactiveCount = $this->countInactiveSessions($cutoffDate);
        $totalCount = $expiredCount + $inactiveCount;

        if ($totalCount === 0) {
            $this->info('No sessions need cleanup.');
            return Command::SUCCESS;
        }

        $this->table(
            ['Type', 'Count'],
            [
                ['Expired Sessions', $expiredCount],
                ['Inactive Sessions (>' . $days . ' days)', $inactiveCount],
                ['Total', $totalCount],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY RUN: No sessions were actually cleaned.');
            return Command::SUCCESS;
        }

        // Perform cleanup
        $this->info('Cleaning up expired sessions...');
        $cleanedExpired = $sessionService->cleanupExpiredSessions();
        
        $this->info('Cleaning up inactive sessions...');
        $cleanedInactive = $this->cleanupInactiveSessions($cutoffDate);
        
        // Clean up Laravel's session table if using database driver
        if (config('session.driver') === 'database') {
            $this->info('Cleaning up Laravel session table...');
            $cleanedLaravelSessions = $this->cleanupLaravelSessions($cutoffDate);
            $this->info("Cleaned up {$cleanedLaravelSessions} Laravel session records.");
        }

        $total = $cleanedExpired + $cleanedInactive;
        
        $this->info("Session cleanup completed!");
        $this->info("Cleaned up {$total} sessions ({$cleanedExpired} expired, {$cleanedInactive} inactive).");
        
        // Log the cleanup
        Log::info('Session cleanup completed', [
            'expired_sessions_cleaned' => $cleanedExpired,
            'inactive_sessions_cleaned' => $cleanedInactive,
            'total_cleaned' => $total,
            'cutoff_days' => $days,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Count expired sessions
     */
    private function countExpiredSessions(): int
    {
        return DB::table('user_sessions')
            ->where(function ($query) {
                $query->where('expires_at', '<', Carbon::now())
                    ->orWhere('is_active', false);
            })
            ->count();
    }

    /**
     * Count inactive sessions
     */
    private function countInactiveSessions(Carbon $cutoffDate): int
    {
        return DB::table('user_sessions')
            ->where('last_activity', '<', $cutoffDate)
            ->where('is_active', true)
            ->count();
    }

    /**
     * Clean up inactive sessions
     */
    private function cleanupInactiveSessions(Carbon $cutoffDate): int
    {
        return DB::table('user_sessions')
            ->where('last_activity', '<', $cutoffDate)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'invalidated_at' => Carbon::now(),
                'invalidation_reason' => 'cleanup_inactive',
                'updated_at' => Carbon::now(),
            ]);
    }

    /**
     * Clean up Laravel's session table
     */
    private function cleanupLaravelSessions(Carbon $cutoffDate): int
    {
        $sessionTable = config('session.table', 'sessions');
        
        return DB::table($sessionTable)
            ->where('last_activity', '<', $cutoffDate->timestamp)
            ->delete();
    }
}