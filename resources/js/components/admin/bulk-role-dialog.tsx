import { useState } from 'react';
import { roleLabel } from '@/components/activity-timeline';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslations } from '@/hooks/use-translations';

/**
 * Chooses the role for a bulk grant or removal (#23). Loaded the first time
 * it is needed (ADR-0026).
 */
export default function BulkRoleDialog({
    action,
    roles,
    count,
    onConfirm,
    onClose,
}: {
    action: 'assign' | 'revoke' | null;
    roles: string[];
    count: number;
    onConfirm: (role: string) => void;
    onClose: () => void;
}) {
    const { t } = useTranslations();
    const [role, setRole] = useState(roles[0] ?? '');

    return (
        <Dialog
            open={action !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                <DialogTitle>
                    {action === 'assign'
                        ? t('Grant a role')
                        : t('Remove a role')}
                </DialogTitle>
                <DialogDescription>
                    {t(
                        'Applies to the :count selected users. Each change is checked and recorded on its own.',
                        { count },
                    )}
                </DialogDescription>
                <div className="grid gap-2">
                    <span id="bulk-role-label" className="text-sm font-medium">
                        {t('Role')}
                    </span>
                    <Select value={role} onValueChange={setRole}>
                        <SelectTrigger
                            aria-labelledby="bulk-role-label"
                            data-test="bulk-role"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((value) => (
                                <SelectItem key={value} value={value}>
                                    {roleLabel(t, value)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">{t('Cancel')}</Button>
                    </DialogClose>
                    <Button
                        variant={
                            action === 'revoke' ? 'destructive' : 'default'
                        }
                        onClick={() => onConfirm(role)}
                        data-test="confirm-bulk"
                    >
                        {action === 'assign' ? t('Grant') : t('Remove')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
