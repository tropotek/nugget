<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent to a user an admin has just created, in place of a password typed by
 * the admin. The link is an ordinary password reset link.
 */
class SetPassword extends ResetPassword
{
    /**
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Set your '.config('app.name').' password')
            ->line('An account has been created for you on '.config('app.name').'. Choose a password to sign in.')
            ->action('Set password', $url)
            ->line("This link works for {$minutes} minutes. After that, use \"Forgot your password?\" on the login page to get a new one.");
    }
}
