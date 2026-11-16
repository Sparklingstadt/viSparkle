import { Link } from '@inertiajs/react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { home } from '@/routes';
import type { CreatorProfile } from '@/types';

export function CreatorHeader({
    creator,
    compact = false,
    action,
}: {
    creator: CreatorProfile;
    compact?: boolean;
    action?: React.ReactNode;
}) {
    if (compact) {
        return (
            <div className="flex items-center gap-3">
                <Avatar className="size-11">
                    <AvatarImage src={creator.avatar_url} alt="" />
                    <AvatarFallback>{creator.name.slice(0, 1)}</AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <Link href={home()} className="font-bold hover:underline">
                        {creator.name}
                    </Link>
                </div>
                {action && <div className="shrink-0">{action}</div>}
            </div>
        );
    }

    return (
        <div>
            <div className="aspect-[3/1] overflow-hidden rounded-3xl bg-secondary sm:aspect-[4/1]">
                <img
                    src={creator.header_url}
                    alt=""
                    className="size-full object-cover"
                />
            </div>
            <div className="flex flex-wrap items-end gap-x-5 gap-y-3 px-4 sm:px-8">
                <Avatar className="-mt-10 size-20 border-4 border-background sm:-mt-14 sm:size-28">
                    <AvatarImage src={creator.avatar_url} alt="" />
                    <AvatarFallback className="text-2xl">
                        {creator.name.slice(0, 1)}
                    </AvatarFallback>
                </Avatar>
                <div className="order-last min-w-0 basis-full pb-1 sm:order-none sm:flex-1 sm:basis-0">
                    <h1 className="text-2xl font-black sm:text-3xl">
                        {creator.name}
                    </h1>
                </div>
                {action && (
                    <div className="ml-auto flex shrink-0 items-center gap-2 pb-1">
                        {action}
                    </div>
                )}
            </div>
            {creator.bio && (
                <p className="mt-4 max-w-2xl px-4 leading-relaxed whitespace-pre-wrap sm:px-8">
                    {creator.bio}
                </p>
            )}
        </div>
    );
}
