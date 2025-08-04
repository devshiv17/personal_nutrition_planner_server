<?php

namespace App\Console\Commands;

use App\Models\LoginAttempt;
use Illuminate\Console\Command;

class CleanupLoginAttempts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:cleanup-login-attempts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old login attempt records older than 30 days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting cleanup of old login attempts...');

        try {
            $deletedCount = LoginAttempt::cleanup();
            
            $this->info("Successfully deleted {$deletedCount} old login attempt records.");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to cleanup login attempts: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    }
}
