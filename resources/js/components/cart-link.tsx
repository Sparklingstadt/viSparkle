import { Link, usePage } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { index as cart } from '@/routes/cart';

export function CartLink() {
    const { cartCount } = usePage().props;

    return (
        <Button asChild variant="ghost" size="icon" className="relative size-8">
            <Link
                href={cart()}
                aria-label={
                    cartCount > 0 ? `カート（${cartCount}点）` : 'カート'
                }
            >
                <ShoppingCart className="size-4" />
                {cartCount > 0 && (
                    <span
                        aria-hidden
                        data-test="cart-count"
                        className="absolute -top-1 -right-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 text-[10px] font-bold text-primary-foreground"
                    >
                        {cartCount > 99 ? '99+' : cartCount}
                    </span>
                )}
            </Link>
        </Button>
    );
}
