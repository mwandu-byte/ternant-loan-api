<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class ResetPasswordNotification extends Notification
{
    public function __construct(public readonly string $otp)
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your Password Reset Code')
            ->line('You are receiving this email because we received a password reset request for your account.')
            ->line('Enter this code in the app to reset your password:')
            ->line(new HtmlString(
                '<div style="font-size: 28px; font-weight: bold; letter-spacing: 8px; text-align: center;">'.$this->otp.'</div>'
            ))
            ->line('This code will expire in '.config('password_reset.otp_expire_minutes').' minutes.')
            ->line('If you did not request a password reset, no further action is required.');
    }
}
