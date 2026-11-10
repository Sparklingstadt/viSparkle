import { Link } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { formatYen } from '@/lib/format';
import { show } from '@/routes/creators';
import type { CreatorCard as CreatorCardData } from '@/types';

/** A creator with header image, avatar, one line of bio and the cheapest plan; `rank` adds a ranking chip. */
export function CreatorCard({
    creator,
    rank,
}: {
    creator: CreatorCardData;
    rank?: number;
}) {
    return (
        <Link
            href={show(creator.slug)}
            className="flex h-full flex-col overflow-hidden rounded-2xl border bg-card transition-colors hover:border-primary/40"
        >
            <div className="relative h-20 bg-secondary">
                {creator.header_url && (
                    <img
                        src={creator.header_url}
                        alt=""
                        loading="lazy"
                        className="size-full object-cover"
                    />
                )}
                {rank !== undefined && (
                    <span className="absolute top-2 left-2 rounded-full bg-background px-2 py-0.5 text-xs font-bold">
                        {rank}位
                    </span>
                )}
            </div>
            <div className="flex flex-1 flex-col gap-1.5 px-3.5 pb-4">
                <Avatar className="-mt-6 size-12 border-[3px] border-card">
                    {creator.avatar_url && (
                        <AvatarImage src={creator.avatar_url} alt="" />
                    )}
                    <AvatarFallback>
                        {creator.display_name.slice(0, 1)}
                    </AvatarFallback>
                </Avatar>
                <p className="truncate font-bold">{creator.display_name}</p>
                {creator.bio && (
                    <p className="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                        {creator.bio}
                    </p>
                )}
                {creator.min_plan_price !== null && (
                    <p className="mt-auto pt-1 text-xs font-bold text-primary">
                        {formatYen(creator.min_plan_price)} から支援できます
                    </p>
                )}
            </div>
        </Link>
    );
}
