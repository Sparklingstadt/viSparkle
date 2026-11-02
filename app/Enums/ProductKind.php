<?php

namespace App\Enums;

enum ProductKind: string
{
    case Physical = 'physical';
    case Digital = 'digital';

    public function label(): string
    {
        return match ($this) {
            self::Physical => 'グッズ',
            self::Digital => 'デジタル作品',
        };
    }
}
