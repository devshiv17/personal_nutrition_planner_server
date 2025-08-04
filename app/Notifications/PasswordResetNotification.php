<?php

namespace App\Notifications;

use App\Models\PasswordResetToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected PasswordResetToken $resetToken;
    protected string $resetUrl;

    /**
     * Create a new notification instance.
     */
    public function __construct(PasswordResetToken $resetToken, string $resetUrl)
    {
        $this->resetToken = $resetToken;
        $this->resetUrl = $resetUrl;
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
            ->subject('Reset Your Password - Personal Nutrition Planner')
            ->view('emails.password-reset', [
                'user' => $notifiable,
                'resetToken' => $this->resetToken,
                'resetUrl' => $this->resetUrl,
            ]);
    }
}
