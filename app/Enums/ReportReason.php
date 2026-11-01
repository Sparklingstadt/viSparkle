<?php

namespace App\Enums;

enum ReportReason: string
{
    case Inappropriate = 'inappropriate';
    case Rights = 'rights';
    case Adult = 'adult';
    case Spam = 'spam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Inappropriate => '不適切な内容',
            self::Rights => '権利侵害',
            self::Adult => '成人向けの内容',
            self::Spam => 'スパム',
            self::Other => 'その他',
        };
    }
}
