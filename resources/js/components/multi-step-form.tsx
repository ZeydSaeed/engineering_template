import { Form } from '@inertiajs/react';
import type { FormComponentSlotProps } from '@inertiajs/core';
import { Check } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';
import type { RouteFormDefinition } from '@/wayfinder';

export type Step = {
    id: string;
    title: string;
    /** Fields checked by the server before leaving this step. */
    fields: string[];
    render: (form: FormComponentSlotProps) => ReactNode;
};

/**
 * A long form in steps (improvement #6). "Next" asks the server to check
 * only this step's fields with the final rules (Precognition, #7); the last
 * step submits everything, checked again. Every step stays in the form, so
 * earlier answers are sent too. On each step change focus moves to the
 * step's heading, so screen readers announce where the person is.
 */
export default function MultiStepForm({
    form,
    steps,
    submitLabel,
}: {
    form: RouteFormDefinition<'post'>;
    steps: Step[];
    submitLabel: string;
}) {
    const { t } = useTranslations();
    const [current, setCurrent] = useState(0);
    const heading = useRef<HTMLHeadingElement>(null);
    const moved = useRef(false);

    useEffect(() => {
        if (moved.current) {
            heading.current?.focus();
        }
    }, [current]);

    const goTo = (index: number) => {
        moved.current = true;
        setCurrent(index);
    };

    const last = current === steps.length - 1;

    return (
        <Form
            {...form}
            className="space-y-8"
            data-slot="multi-step-form"
            // The final check found a problem: show the first step it is on.
            onError={(errors) => {
                const index = steps.findIndex((step) =>
                    step.fields.some((field) => field in errors),
                );

                if (index !== -1 && index !== current) {
                    goTo(index);
                }
            }}
        >
            {(slot) => (
                <>
                    <nav aria-label={t('Progress')}>
                        <p className="mb-3 text-sm text-muted-foreground">
                            {t('Step :current of :total', {
                                current: current + 1,
                                total: steps.length,
                            })}
                        </p>
                        <ol className="flex flex-wrap gap-2">
                            {steps.map((step, index) => {
                                const done = index < current;

                                return (
                                    <li
                                        key={step.id}
                                        aria-current={
                                            index === current
                                                ? 'step'
                                                : undefined
                                        }
                                        data-step={step.id}
                                        className={cn(
                                            'flex items-center gap-2 rounded-full border px-3 py-1 text-sm',
                                            index === current &&
                                                'border-primary bg-primary text-primary-foreground',
                                            done &&
                                                'border-primary text-primary',
                                        )}
                                    >
                                        <span
                                            aria-hidden
                                            className="flex size-5 items-center justify-center rounded-full text-xs"
                                        >
                                            {done ? (
                                                <Check className="size-4" />
                                            ) : (
                                                index + 1
                                            )}
                                        </span>
                                        {step.title}
                                        {done && (
                                            <span className="sr-only">
                                                {t('(completed)')}
                                            </span>
                                        )}
                                    </li>
                                );
                            })}
                        </ol>
                    </nav>

                    {steps.map((step, index) => (
                        <section
                            key={step.id}
                            hidden={index !== current}
                            aria-labelledby={`step-${step.id}`}
                            className="space-y-6"
                        >
                            <h2
                                id={`step-${step.id}`}
                                ref={index === current ? heading : undefined}
                                tabIndex={-1}
                                className="text-lg font-semibold focus:outline-none"
                            >
                                {step.title}
                            </h2>
                            {step.render(slot)}
                        </section>
                    ))}

                    {/* These buttons check the step themselves; fields do
                        not also check on losing focus to them (FormField). */}
                    <div
                        data-checks-fields
                        className="flex flex-wrap items-center gap-3"
                    >
                        {current > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => goTo(current - 1)}
                                data-test="step-back"
                            >
                                {t('Back')}
                            </Button>
                        )}
                        {last ? (
                            <Button
                                type="submit"
                                disabled={slot.processing}
                                data-test="step-submit"
                            >
                                {submitLabel}
                            </Button>
                        ) : (
                            <NextButton
                                url={form.action}
                                slot={slot}
                                fields={steps[current].fields}
                                onPass={() => goTo(current + 1)}
                                label={t('Next')}
                            />
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * "Next": checks the step's fields with one explicit Precognition request
 * to the form's own route (the server's final rules, #7), and moves on only
 * on a pass. Its own request, not the shared validator, because the
 * validator delays a check that follows another (the one sent when the last
 * field lost focus to this button) and skips one whose data it has seen,
 * which made the result arrive late or not at all. Not disabled while busy:
 * the blur check would disable it between press and release, losing the
 * click.
 */
function NextButton({
    url,
    slot,
    fields,
    onPass,
    label,
}: {
    url: string;
    slot: FormComponentSlotProps;
    fields: string[];
    onPass: () => void;
    label: string;
}) {
    const { t } = useTranslations();
    const [busy, setBusy] = useState(false);

    const check = async () => {
        if (fields.length === 0) {
            onPass();

            return;
        }

        setBusy(true);

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Precognition: 'true',
                    'Precognition-Validate-Only': fields.join(','),
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify(slot.getData()),
            });

            if (response.status === 204) {
                slot.clearErrors(...fields);
                onPass();

                return;
            }

            const errors: Record<string, string[]> =
                response.status === 422
                    ? ((
                          (await response.json()) as {
                              errors?: Record<string, string[]>;
                          }
                      ).errors ?? {})
                    : {
                          [fields[0]]: [
                              t('Could not check this step. Try again.'),
                          ],
                      };

            for (const field of fields) {
                if (errors[field]?.[0]) {
                    slot.setError(field, errors[field][0]);
                } else {
                    slot.clearErrors(field);
                }
            }

            const first = fields.find((field) => errors[field]);
            document.querySelector<HTMLElement>(`[name="${first}"]`)?.focus();
        } finally {
            setBusy(false);
        }
    };

    return (
        <Button
            type="button"
            aria-busy={busy || undefined}
            data-test="step-next"
            onClick={() => {
                if (!busy) {
                    void check();
                }
            }}
        >
            {label}
        </Button>
    );
}

/** Laravel's XSRF-TOKEN cookie (readable by design), for the header. */
function csrfToken(): string {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));

    return cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
}
