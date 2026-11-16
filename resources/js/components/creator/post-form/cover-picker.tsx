import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { PostFileData } from '@/types';

/** The cover, chosen among the images in the body (or none). */
export function CoverPicker({
    images,
    value,
    onChange,
    error,
}: {
    images: PostFileData[];
    value: number | null;
    onChange: (imageId: number | null) => void;
    error?: string;
}) {
    return (
        <section className="grid gap-2">
            <Label>カバー画像（本文中の画像から選択）</Label>
            <p className="text-xs text-muted-foreground">
                カバー画像の縮小版は、支援していない人にも表示されます（一覧ではぼかし表示）。限定公開したい画像は選ばないでください。
            </p>
            {images.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    本文に画像を追加すると選択できます。
                </p>
            ) : (
                <div className="grid grid-cols-3 gap-2">
                    <button
                        type="button"
                        onClick={() => onChange(null)}
                        className={cn(
                            'flex aspect-video items-center justify-center rounded-lg border text-xs',
                            value === null && 'ring-2 ring-primary',
                        )}
                    >
                        なし
                    </button>
                    {images.map((image) => (
                        <button
                            key={image.id}
                            type="button"
                            onClick={() => onChange(image.id)}
                            className={cn(
                                'aspect-video overflow-hidden rounded-lg border',
                                value === image.id && 'ring-2 ring-primary',
                            )}
                        >
                            <img
                                src={image.thumb_url}
                                alt=""
                                className="size-full object-cover"
                            />
                        </button>
                    ))}
                </div>
            )}
            <InputError message={error} />
        </section>
    );
}
