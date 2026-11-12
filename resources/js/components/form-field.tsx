import { useId } from 'react';
import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

/**
 * The props a field gives its control, so label, help text, error and the
 * required state reach assistive technology the same way everywhere.
 */
export type FieldControlProps = {
    id: string;
    name: string;
    required?: boolean;
    'aria-required'?: boolean;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
    onBlur?: () => void;
};

/**
 * One form field (improvement #25): label, optional help text, the control
 * and its error, wired together by id.
 *
 *   <FormField name="email" label={t('Email address')} error={errors.email}
 *       required onValidate={() => validate('email')}>
 *       {(control) => <Input type="email" {...control} />}
 *   </FormField>
 *
 * `onValidate` runs when the control loses focus: with an Inertia <Form>,
 * pass `() => validate(name)` and the server's own rules check the value
 * (Laravel Precognition, improvement #7). The error region is a polite live
 * region, so an error that appears while typing is announced.
 */
export default function FormField({
    name,
    label,
    description,
    error,
    required = false,
    hideLabel = false,
    onValidate,
    className,
    children,
}: {
    name: string;
    label: string;
    description?: string;
    error?: string;
    required?: boolean;
    hideLabel?: boolean;
    onValidate?: () => void;
    className?: string;
    children: (control: FieldControlProps) => ReactNode;
}) {
    const { t } = useTranslations();
    const id = `${useId()}-${name}`;
    const descriptionId = description ? `${id}-description` : undefined;
    const errorId = `${id}-error`;
    const describedBy =
        [descriptionId, error ? errorId : undefined]
            .filter(Boolean)
            .join(' ') || undefined;

    return (
        <div className={cn('grid gap-2', className)} data-slot="form-field">
            <Label htmlFor={id} className={cn(hideLabel && 'sr-only')}>
                {label}
                {required && (
                    <>
                        <span
                            aria-hidden
                            className="text-destructive-foreground"
                        >
                            *
                        </span>
                        <span className="sr-only">{t('(required)')}</span>
                    </>
                )}
            </Label>

            {description && (
                <p id={descriptionId} className="text-sm text-muted-foreground">
                    {description}
                </p>
            )}

            {children({
                id,
                name,
                required: required || undefined,
                'aria-required': required || undefined,
                'aria-invalid': error ? true : undefined,
                'aria-describedby': describedBy,
                onBlur: onValidate,
            })}

            <p
                id={errorId}
                aria-live="polite"
                className="text-sm text-destructive-foreground empty:hidden"
            >
                {error}
            </p>
        </div>
    );
}
