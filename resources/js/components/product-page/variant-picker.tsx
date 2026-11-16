import { cn } from '@/lib/utils';

export type ProductVariantOption = {
    id: number;
    name: string | null;
    stock: number;
};

/** The kinds of a product (sizes, designs) with how many are left of each. */
export function VariantPicker({
    variants,
    value,
    onChange,
}: {
    variants: ProductVariantOption[];
    value: number;
    onChange: (variantId: number) => void;
}) {
    return (
        <div className="space-y-2">
            <p className="text-sm font-bold">種類</p>
            <div
                role="radiogroup"
                aria-label="種類"
                className="grid grid-cols-3 gap-2 sm:grid-cols-4"
            >
                {variants.map((v) => (
                    <button
                        key={v.id}
                        type="button"
                        role="radio"
                        aria-checked={v.id === value}
                        onClick={() => onChange(v.id)}
                        className={cn(
                            'flex min-h-14 flex-col items-center justify-center gap-0.5 rounded-2xl px-2 py-2 text-center transition-colors',
                            v.id === value
                                ? 'border-2 border-primary bg-secondary'
                                : v.stock < 1
                                  ? 'border border-dashed bg-muted/50 text-muted-foreground'
                                  : 'border hover:border-primary/50',
                        )}
                    >
                        <span
                            className={cn(
                                'text-sm font-bold',
                                v.stock < 1 && 'line-through',
                            )}
                        >
                            {v.name}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {v.stock < 1
                                ? '売り切れ'
                                : v.stock <= 3
                                  ? `残り ${v.stock}`
                                  : '在庫あり'}
                        </span>
                    </button>
                ))}
            </div>
        </div>
    );
}
