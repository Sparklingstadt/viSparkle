import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * A one-file upload form posted to `form`, cleared after it succeeds. `showProgress` puts the upload's percentage on
 * the button (for large files).
 */
export function UploadField({
    form,
    id,
    name,
    label,
    accept,
    showProgress = false,
}: {
    form: RouteFormDefinition<'post'>;
    id: string;
    name: string;
    label: string;
    accept?: string;
    showProgress?: boolean;
}) {
    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid max-w-sm gap-2"
        >
            {({ processing, progress, errors }) => (
                <>
                    <Label htmlFor={id}>{label}</Label>
                    <Input
                        id={id}
                        name={name}
                        type="file"
                        accept={accept}
                        required
                    />
                    <InputError message={errors[name]} />
                    <Button
                        variant="outline"
                        size="sm"
                        className="w-fit"
                        disabled={processing}
                    >
                        {showProgress && progress
                            ? `アップロード中…${progress.percentage ?? 0}%`
                            : 'アップロード'}
                    </Button>
                </>
            )}
        </Form>
    );
}
