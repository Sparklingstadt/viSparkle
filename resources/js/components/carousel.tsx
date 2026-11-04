import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function Carousel({
    title,
    description,
    trackClassName,
    children,
}: {
    title: string;
    description?: string;
    trackClassName?: string;
    children: React.ReactNode;
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
    }, [updateButtons, children]);

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
            <div className="space-y-1">
                <h2 className="text-lg font-semibold">{title}</h2>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            <div className="relative">
                {canScrollPrev && (
                    <Button
                        size="icon"
                        variant="outline"
                        onClick={() => scrollByPage(-1)}
                        className="absolute top-1/2 -left-3 z-10 -translate-y-1/2 rounded-full bg-background shadow-md"
                    >
                        <ChevronLeft />
                        <span className="sr-only">前へ</span>
                    </Button>
                )}
                {canScrollNext && (
                    <Button
                        size="icon"
                        variant="outline"
                        onClick={() => scrollByPage(1)}
                        className="absolute top-1/2 -right-3 z-10 -translate-y-1/2 rounded-full bg-background shadow-md"
                    >
                        <ChevronRight />
                        <span className="sr-only">次へ</span>
                    </Button>
                )}
                <ul
                    ref={trackRef}
                    className={cn(
                        'flex snap-x snap-mandatory gap-4 overflow-x-auto scroll-smooth pb-2',
                        trackClassName,
                    )}
                >
                    {children}
                </ul>
            </div>
        </section>
    );
}
