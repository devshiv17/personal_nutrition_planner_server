<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class PasswordResetSuccessNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected string $ipAddress;
    protected Carbon $resetTime;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $ipAddress, Carbon $resetTime)
    {
        $this->ipAddress = $ipAddress;
        $this->resetTime = $resetTime;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Password Reset Successful - Personal Nutrition Planner')
            ->view('emails.password-reset-success', [
                'user' => $notifiable,
                'ipAddress' => $this->ipAddress,
                'resetTime' => $this->resetTime,
            ]);
    }
}
