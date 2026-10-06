import { Link } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { show } from '@/routes/creators';
import type { CreatorProfile } from '@/types';

export function CreatorHeader({
    creator,
    compact = false,
}: {
    creator: CreatorProfile;
    compact?: boolean;
}) {
    return (
        <div className="overflow-hidden rounded-xl border">
            {!compact && (
                <div className="aspect-[4/1] bg-muted">
                    {creator.header_url && (
                        <img
                            src={creator.header_url}
                            alt=""
                            className="size-full object-cover"
                        />
                    )}
                </div>
            )}
            <div className="flex items-center gap-4 p-4">
                <Avatar className="size-16">
                    {creator.avatar_url && (
                        <AvatarImage src={creator.avatar_url} alt="" />
                    )}
                    <AvatarFallback>
                        {creator.display_name.slice(0, 1)}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0">
                    <Link
                        href={show(creator.slug)}
                        className="text-xl font-bold hover:underline"
                    >
                        {creator.display_name}
                    </Link>
                    <p className="text-sm text-muted-foreground">
                        @{creator.slug}
                    </p>
                </div>
            </div>
            {!compact && creator.bio && (
                <p className="px-4 pb-4 text-sm whitespace-pre-wrap">
                    {creator.bio}
                </p>
            )}
        </div>
    );
}
