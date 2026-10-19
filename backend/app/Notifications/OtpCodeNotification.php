<?php

namespace App\Notifications;

use App\Enums\ChallengePurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCodeNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly ChallengePurpose $purpose,
        public readonly int $expiresInMinutes = 5
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = match ($this->purpose) {
            ChallengePurpose::LOGIN => 'Your Finovo Login Verification Code',
            ChallengePurpose::PASSWORD_RESET => 'Your Finovo Password Reset Code',
        };

        $actionText = match ($this->purpose) {
            ChallengePurpose::LOGIN => 'logging in to your workspace',
            ChallengePurpose::PASSWORD_RESET => 'resetting your password',
        };

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line("Use the verification code below to complete {$actionText}:")
            ->line("**{$this->code}**")
            ->line("This code will expire in {$this->expiresInMinutes} minutes.")
            ->line('For security reasons, never share this code with anyone.')
            ->line('If you did not request this verification code, please ignore this email or notify your system administrator.');
    }
}
