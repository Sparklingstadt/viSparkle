import { Paperclip, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes } from '@/lib/format';
import type { PostFileData } from '@/types';

/** The files only supporters download, with a picker that uploads the chosen files at once. */
export function AttachmentList({
    attachments,
    onAdd,
    onRemove,
}: {
    attachments: PostFileData[];
    onAdd: (files: File[]) => void;
    onRemove: (file: PostFileData) => void;
}) {
    return (
        <section className="grid gap-2">
            <Label htmlFor="attachment">添付ファイル（支援者のみ）</Label>
            <ul className="space-y-1">
                {attachments.map((file) => (
                    <li
                        key={file.id}
                        className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                    >
                        <span className="flex min-w-0 items-center gap-2">
                            <Paperclip className="size-4 shrink-0" />
                            <span className="truncate">
                                {file.original_name}
                            </span>
                            <span className="shrink-0 text-muted-foreground">
                                {formatBytes(file.size)}
                            </span>
                        </span>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="削除"
                            onClick={() => onRemove(file)}
                        >
                            <Trash2 />
                        </Button>
                    </li>
                ))}
            </ul>
            <Input
                id="attachment"
                type="file"
                multiple
                onChange={(e) => {
                    const files = Array.from(e.target.files ?? []);
                    e.target.value = '';
                    onAdd(files);
                }}
            />
        </section>
    );
}
