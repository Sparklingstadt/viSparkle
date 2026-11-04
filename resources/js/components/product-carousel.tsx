import { Link } from '@inertiajs/react';
import { Carousel } from '@/components/carousel';
import {
    ProductLikeButton,
    type LikeableProduct,
} from '@/components/product-like-button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { formatYen } from '@/lib/format';
import { show as showProduct } from '@/routes/shop/products';

export function ProductCarousel({
    title,
    products,
}: {
    title: string;
    products: LikeableProduct[];
}) {
    return (
        <Carousel title={title}>
            {products.map((product) => (
                <li
                    key={product.id}
                    className="relative w-[152px] shrink-0 snap-start"
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
                            <p className="line-clamp-2 text-sm font-medium">
                                {product.name}
                            </p>
                            <p className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
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
                    <ProductLikeButton
                        product={product}
                        className="absolute right-1.5 bottom-1.5"
                    />
                </li>
            ))}
        </Carousel>
    );
}
