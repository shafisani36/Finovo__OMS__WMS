<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $ipAddress,
        public readonly string $timestamp
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Security Alert: Your Finovo Password Was Changed')
            ->greeting("Hello {$notifiable->name},")
            ->line('Your password for your Finovo OMS / WMS account was successfully changed.')
            ->line("Time: {$this->timestamp} UTC")
            ->line("IP Address: {$this->ipAddress}")
            ->line('If you made this change, no further action is required.')
            ->line('If you did NOT authorize this change, please contact your security administrator immediately to secure your account.')
            ->salutation('Regards, Finovo Security Team');
    }
}
