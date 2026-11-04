import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import { show as showProduct } from '@/routes/shop/products';
import type { CreatorSummary, ShopProductSummary } from '@/types';

type CarouselProduct = ShopProductSummary & { creator: CreatorSummary };

export function ProductCarousel({
    title,
    products,
}: {
    title: string;
    products: CarouselProduct[];
}) {
    const trackRef = useRef<HTMLUListElement>(null);
    const [canScrollPrev, setCanScrollPrev] = useState(false);
    const [canScrollNext, setCanScrollNext] = useState(false);

    const updateButtons = useCallback(() => {
        const track = trackRef.current;

        if (!track) {
            return;
        }

        // 端数のずれで最後まで届かないことがあるため、1px の余裕を持たせる
        setCanScrollPrev(track.scrollLeft > 1);
        setCanScrollNext(
            track.scrollLeft + track.clientWidth < track.scrollWidth - 1,
        );
    }, []);

    useEffect(() => {
        const track = trackRef.current;

        if (!track) {
            return;
        }

        updateButtons();
        track.addEventListener('scroll', updateButtons, { passive: true });
        const observer = new ResizeObserver(updateButtons);
        observer.observe(track);

        return () => {
            track.removeEventListener('scroll', updateButtons);
            observer.disconnect();
        };
    }, [updateButtons, products]);

    const scrollByPage = (direction: 1 | -1) => {
        const track = trackRef.current;

        if (!track) {
            return;
        }

        track.scrollBy({
            left: direction * track.clientWidth * 0.9,
            behavior: 'smooth',
        });
    };

    return (
        <section className="space-y-4" aria-roledescription="カルーセル">
            <div className="flex items-center justify-between gap-2">
                <h2 className="text-lg font-semibold">{title}</h2>
                {(canScrollPrev || canScrollNext) && (
                    <div className="flex gap-1">
                        <Button
                            size="icon"
                            variant="outline"
                            onClick={() => scrollByPage(-1)}
                            disabled={!canScrollPrev}
                        >
                            <ChevronLeft />
                            <span className="sr-only">前へ</span>
                        </Button>
                        <Button
                            size="icon"
                            variant="outline"
                            onClick={() => scrollByPage(1)}
                            disabled={!canScrollNext}
                        >
                            <ChevronRight />
                            <span className="sr-only">次へ</span>
                        </Button>
                    </div>
                )}
            </div>
            <ul
                ref={trackRef}
                className="flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth pb-2"
            >
                {products.map((product) => (
                    <li
                        key={product.id}
                        className="w-40 shrink-0 snap-start sm:w-48"
                    >
                        <Link
                            href={showProduct({
                                creator: product.creator.slug,
                                product: product.id,
                            })}
                            className="flex h-full flex-col overflow-hidden rounded-xl border bg-card transition-colors hover:bg-muted/40"
                        >
                            <div className="aspect-square bg-muted">
                                {product.cover_url && (
                                    <img
                                        src={product.cover_url}
                                        alt=""
                                        loading="lazy"
                                        className="size-full object-cover"
                                    />
                                )}
                            </div>
                            <div className="space-y-1 p-3">
                                <p className="line-clamp-2 font-medium">
                                    {product.name}
                                </p>
                                <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <Avatar className="size-4 shrink-0">
                                        {product.creator.avatar_url && (
                                            <AvatarImage
                                                src={product.creator.avatar_url}
                                                alt=""
                                            />
                                        )}
                                        <AvatarFallback className="text-[10px]">
                                            {product.creator.display_name.slice(
                                                0,
                                                1,
                                            )}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="truncate">
                                        {product.creator.display_name}
                                    </span>
                                </p>
                                <p className="flex items-center gap-2 text-sm">
                                    {formatYen(product.price)}
                                    {product.sold_out && (
                                        <Badge variant="secondary">
                                            売り切れ
                                        </Badge>
                                    )}
                                </p>
                            </div>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
