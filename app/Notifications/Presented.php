<?php

namespace App\Notifications;

/**
 * A notification as it is shown: a line of title, a line of text, where a
 * click on it leads, and a Remix icon class.
 */
final readonly class Presented
{
    public function __construct(
        public string $title,
        public string $text,
        public string $url,
        public string $icon,
    ) {}
}
