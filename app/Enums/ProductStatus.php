<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Aktif = 'aktif';
    case Habis = 'habis';
    case Nonaktif = 'nonaktif';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
