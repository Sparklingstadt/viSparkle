import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';

/** The mark on a list row of a post or product the admin made private. */
export function HiddenByAdminBadge() {
    return <Badge variant="destructive">非公開（運営）</Badge>;
}

/** The notice on a post or product the admin made private, with the reason the creator is told. */
export function HiddenByAdminAlert({ reason }: { reason: string | null }) {
    return (
        <Alert variant="destructive">
            <AlertDescription>
                運営により非公開になっています（理由: {reason}）
            </AlertDescription>
        </Alert>
    );
}
