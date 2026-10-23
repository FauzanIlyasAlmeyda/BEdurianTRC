<?php

namespace App\Enums;

enum ConsumerTransactionStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
