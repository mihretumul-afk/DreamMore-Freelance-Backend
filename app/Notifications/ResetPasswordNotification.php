<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    /**
     * The password reset token.
     */
    public string $token;

    /**
     * Create a new notification instance.
     */
    public function __construct(string $token)
    {
        $this->token = $token;
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
     * Build the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $email = urlencode($notifiable->getEmailForPasswordReset());
        $url = "{$frontendUrl}/reset-password?token={$this->token}&email={$email}";

        return (new MailMessage)
            ->subject('Reset Your Password — DreamMore Freelance')
            ->greeting("Hello {$notifiable->name},")
            ->line('You are receiving this email because we received a password reset request for your DreamMore Freelance account.')
            ->action('Reset Password', $url)
            ->line('This password reset link will expire in 60 minutes.')
            ->line('If you did not request a password reset, no further action is required. Your password will remain unchanged.')
            ->salutation('Regards, The DreamMore Team');
    }
}
