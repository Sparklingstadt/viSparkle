<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Canceled = 'canceled';
    case Refunded = 'refunded';

    /**
     * The order was paid for and stands: the buyer owns what it holds (sales, digital access).
     *
     * @return list<self>
     */
    public static function purchased(): array
    {
        return [self::Paid, self::Shipped];
    }

    /**
     * The order was paid at some point, refunded since or not: the orders listed to the creator and the admin.
     *
     * @return list<self>
     */
    public static function everPaid(): array
    {
        return [self::Paid, self::Shipped, self::Refunded];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => '支払い待ち',
            self::Paid => '支払い済み',
            self::Shipped => '発送済み',
            self::Canceled => '取り消し',
            self::Refunded => '返金済み',
        };
    }
}
