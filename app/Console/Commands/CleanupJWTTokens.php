<?php

namespace App\Console\Commands;

use App\Models\JWTRefreshToken;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CleanupJWTTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jwt:cleanup-tokens';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired and revoked JWT refresh tokens';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting cleanup of JWT tokens...');

        try {
            // Clean up database tokens
            $deletedCount = JWTRefreshToken::cleanup();
            $this->info("Successfully deleted {$deletedCount} expired/revoked refresh tokens from database.");

            // Clean up cache-based blacklisted tokens (optional - they expire automatically)
            $this->cleanupCacheTokens();

            $this->info('JWT token cleanup completed successfully.');
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Failed to cleanup JWT tokens: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    }

    /**
     * Clean up cache-based token data (optional)
     */
    private function cleanupCacheTokens(): void
    {
        try {
            // This is optional since cache entries expire automatically
            // But you might want to clean up old entries for storage optimization
            
            $this->line('Cache-based tokens expire automatically - no manual cleanup needed.');
        } catch (\Exception $e) {
            $this->warn('Could not clean cache tokens: ' . $e->getMessage());
        }
    }
}
