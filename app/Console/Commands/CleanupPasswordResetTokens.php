<?php

namespace App\Console\Commands;

use App\Models\PasswordResetToken;
use Illuminate\Console\Command;

class CleanupPasswordResetTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:cleanup-password-reset-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired and used password reset tokens';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting cleanup of expired password reset tokens...');

        try {
            $deletedCount = PasswordResetToken::cleanup();
            
            $this->info("Successfully deleted {$deletedCount} expired/used password reset tokens.");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to cleanup password reset tokens: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    }
}
