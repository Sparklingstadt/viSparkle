import { useState } from 'react';
import { cn } from '@/lib/utils';

/** The product's main image, with thumbnails to switch it when there are several. */
export function ImageGallery({ images }: { images: string[] }) {
    const [current, setCurrent] = useState(0);

    return (
        <div className="space-y-2">
            <div className="aspect-square overflow-hidden rounded-3xl bg-secondary">
                {images[current] && (
                    <img
                        src={images[current]}
                        alt=""
                        className="size-full object-cover"
                    />
                )}
            </div>
            {images.length > 1 && (
                <div className="flex flex-wrap gap-2">
                    {images.map((url, index) => (
                        <button
                            key={url}
                            type="button"
                            onClick={() => setCurrent(index)}
                            aria-label={`画像 ${index + 1}`}
                            className={cn(
                                'size-16 overflow-hidden rounded-md border',
                                index === current && 'ring-2 ring-primary',
                            )}
                        >
                            <img
                                src={url}
                                alt=""
                                className="size-full object-cover"
                            />
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
