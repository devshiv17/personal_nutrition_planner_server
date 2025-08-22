<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule session cleanup to run daily at 2 AM
Schedule::command('sessions:cleanup --force --days=30')
    ->dailyAt('02:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/session-cleanup.log'));

// Schedule JWT token cleanup
Schedule::command('jwt:cleanup-tokens --force')
    ->dailyAt('02:30')
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/jwt-cleanup.log'));

// Schedule login attempts cleanup
Schedule::command('login-attempts:cleanup --force')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/login-attempts-cleanup.log'));

// Schedule password reset tokens cleanup
Schedule::command('password-reset:cleanup --force')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/password-reset-cleanup.log'));
