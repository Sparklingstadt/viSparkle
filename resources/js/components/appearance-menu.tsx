import type { LucideIcon } from 'lucide-react';
import { Check, Monitor, Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';

const OPTIONS: { value: Appearance; icon: LucideIcon; label: string }[] = [
    { value: 'light', icon: Sun, label: 'ライト' },
    { value: 'dark', icon: Moon, label: 'ダーク' },
    { value: 'system', icon: Monitor, label: 'システムに合わせる' },
];

/**
 * Top-bar theme switcher. Works without logging in: the choice is kept in localStorage and the `appearance` cookie.
 */
export function AppearanceMenu() {
    const { appearance, updateAppearance } = useAppearance();
    const current =
        OPTIONS.find((option) => option.value === appearance) ?? OPTIONS[2];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={`表示テーマ（現在: ${current.label}）`}
                >
                    <current.icon className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {OPTIONS.map(({ value, icon: Icon, label }) => (
                    <DropdownMenuItem
                        key={value}
                        onSelect={() => updateAppearance(value)}
                    >
                        <Icon className="size-4" />
                        <span className="flex-1">{label}</span>
                        {appearance === value && <Check className="size-4" />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
