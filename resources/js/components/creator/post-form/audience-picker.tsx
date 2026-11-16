import InputError from '@/components/input-error';
import { formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';

export type Audience = {
    price: number;
    plans: string[];
    readers: number | null;
};

/** Who can read the post: everyone, or supporters from a plan price up, with how many supporters that is. */
export function AudiencePicker({
    audiences,
    value,
    onChange,
    error,
}: {
    audiences: Audience[];
    value: number;
    onChange: (price: number) => void;
    error?: string;
}) {
    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-bold">読める人</legend>
            {audiences.map((audience) => (
                <label
                    key={audience.price}
                    className={cn(
                        'flex cursor-pointer items-center gap-3 rounded-2xl px-4 py-3 transition-colors',
                        value === audience.price
                            ? 'border-2 border-primary bg-secondary'
                            : 'border hover:border-primary/50',
                    )}
                >
                    <input
                        type="radio"
                        name="required_price"
                        value={audience.price}
                        checked={value === audience.price}
                        onChange={() => onChange(audience.price)}
                        className="size-4 accent-primary"
                    />
                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-bold">
                            {audience.price === 0
                                ? '全体公開'
                                : `${audience.plans.length > 0 ? `${audience.plans.join('・')} ` : ''}${formatYen(audience.price)} 以上`}
                        </span>
                        <span className="block text-xs text-muted-foreground">
                            {audience.readers === null
                                ? '誰でも読めます'
                                : `支援中の ${audience.readers} 人が読めます`}
                        </span>
                    </span>
                </label>
            ))}
            <InputError message={error} />
        </fieldset>
    );
}
