<?php

namespace App\Exchanges;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Why a call to an exchange failed, as a line to show its owner.
 */
class Failure
{
    /**
     * An exchange's own message and code where it gave them, with the HTTP
     * status. Anything else is the exception's message. Never the request,
     * which carries the key.
     */
    public static function reason(Throwable $e): string
    {
        if (! $e instanceof RequestException) {
            return Str::limit($e->getMessage(), 300);
        }

        $answer = $e->response->json();
        $message = is_array($answer) && is_string($answer['message'] ?? null) ? $answer['message'] : null;
        $code = is_array($answer) && is_string($answer['code'] ?? null) ? $answer['code'] : null;
        $status = 'HTTP '.$e->response->status();

        return Str::limit(match (true) {
            $message !== null && $code !== null => "{$message} ({$code}, {$status})",
            $message !== null, $code !== null => ($message ?? $code)." ({$status})",
            default => "The exchange answered {$status}.",
        }, 300);
    }
}
