<?php

declare(strict_types=1);

namespace App\Log;

/** Subscriber activity on a campaign message, recorded in `smlog`. */
enum MessageActivity
{
    case Read;
    case Like;
    case Dislike;
    case Update;
    case Confirm;
    case Forward;
    case Booking;
    case Subscribe;
    case Unsubscribe;

    /** SET clause for this activity; every activity except Read also counts as a read. */
    public function assignments(): string
    {
        $read = 'sml_reads = sml_reads + 1, sml_last_read = :now';
        return match ($this) {
            self::Read => $read,
            self::Like => 'sml_likes = sml_likes + 1, sml_last_like = :now, ' . $read,
            self::Dislike => 'sml_dislikes = sml_dislikes + 1, sml_last_dislike = :now, ' . $read,
            self::Update => 'sml_updates = sml_updates + 1, sml_last_update = :now, ' . $read,
            self::Confirm => 'sml_confirms = sml_confirms + 1, sml_confirmed_at = :now, ' . $read,
            self::Forward => 'sml_forwards = sml_forwards + 1, sml_last_forwarded = :now, ' . $read,
            self::Booking => 'sml_bookings = sml_bookings + 1, sml_last_booking = :now, ' . $read,
            self::Subscribe => 'sml_subscribe = 1, sml_subscribed_at = :now, ' . $read,
            self::Unsubscribe => 'sml_unsubscribe = 1, sml_unsubscribed_at = :now, ' . $read,
        };
    }
}
