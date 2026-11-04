import { Link } from '@inertiajs/react';
import { Carousel } from '@/components/carousel';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
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
    return (
        <Carousel title={title}>
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
                        className="flex h-full flex-col overflow-hidden rounded-xl border bg-card transition-colors hover:border-foreground/30 hover:bg-muted/70"
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
                                    <Badge variant="secondary">売り切れ</Badge>
                                )}
                            </p>
                        </div>
                    </Link>
                </li>
            ))}
        </Carousel>
    );
}
