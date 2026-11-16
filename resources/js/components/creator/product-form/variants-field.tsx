import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { EditableVariant } from './types';

const MAX_VARIANTS = 20;

type VariantRow = { key: number; variant: EditableVariant | null };

/**
 * The kinds (sizes, designs) with their stock. A product without kinds keeps a single row with no name; the names
 * are asked for once there are two or more. Existing kinds also send the stock the form was opened with.
 */
export function VariantsField({
    variants,
    errors,
}: {
    variants: EditableVariant[];
    errors: Record<string, string>;
}) {
    const [rows, setRows] = useState<VariantRow[]>(() =>
        variants.length > 0
            ? variants.map((variant) => ({ key: variant.id, variant }))
            : [{ key: 0, variant: null }],
    );
    const [nextKey, setNextKey] = useState(-1);
    const several = rows.length > 1;

    return (
        <fieldset className="grid gap-2">
            <legend className="mb-2 text-sm font-medium">種類と在庫数</legend>
            <p className="text-xs text-muted-foreground">
                サイズやデザインごとに在庫を分けるときは、種類を追加してください。
            </p>
            {rows.map(({ key, variant }, i) => (
                <div key={key} className="grid gap-1">
                    <div className="flex items-start gap-2">
                        {variant && (
                            <>
                                <input
                                    type="hidden"
                                    name={`variants[${i}][id]`}
                                    value={variant.id}
                                />
                                <input
                                    type="hidden"
                                    name={`variants[${i}][stock_seen]`}
                                    value={variant.stock}
                                />
                            </>
                        )}
                        <Input
                            aria-label="種類の名前"
                            name={`variants[${i}][name]`}
                            defaultValue={variant?.name ?? ''}
                            placeholder={several ? '例: M' : '種類なし'}
                            maxLength={50}
                            required={several}
                            className="flex-1"
                        />
                        <Input
                            aria-label="在庫数"
                            name={`variants[${i}][stock]`}
                            type="number"
                            min={0}
                            max={99999}
                            step={1}
                            defaultValue={variant?.stock ?? 1}
                            required
                            className="w-28"
                        />
                        <Button
                            type="button"
                            size="icon"
                            variant="ghost"
                            aria-label="この種類を削除"
                            disabled={!several}
                            onClick={() =>
                                setRows(rows.filter((row) => row.key !== key))
                            }
                        >
                            <X />
                        </Button>
                    </div>
                    {variant && variant.reserved > 0 && (
                        <p className="text-xs text-muted-foreground">
                            確保中 {variant.reserved} 個
                        </p>
                    )}
                    <InputError message={errors[`variants.${i}.name`]} />
                    <InputError message={errors[`variants.${i}.stock`]} />
                    <InputError message={errors[`variants.${i}.stock_seen`]} />
                </div>
            ))}
            <InputError message={errors.variants} />
            {rows.length < MAX_VARIANTS && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-fit"
                    onClick={() => {
                        setRows([...rows, { key: nextKey, variant: null }]);
                        setNextKey(nextKey - 1);
                    }}
                >
                    <Plus />
                    種類を追加
                </Button>
            )}
        </fieldset>
    );
}
