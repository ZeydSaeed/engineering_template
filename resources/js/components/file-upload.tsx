import { router } from '@inertiajs/react';
import { CircleAlert, CircleCheck, RotateCcw, Upload, X } from 'lucide-react';
import { useId, useRef, useState } from 'react';
import type { DragEvent } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';
import { cn } from '@/lib/utils';

type Status = 'queued' | 'uploading' | 'done' | 'failed' | 'cancelled';

type Item = {
    id: string;
    file: File;
    status: Status;
    progress: number;
    error?: string;
};

/**
 * Upload by dropping files or choosing them (improvement #14), with per-file
 * progress, cancel and retry (#15). Files go one at a time to `url` as
 * `file`; the server (FileStore, ADR-0020) decides what is accepted, so the
 * checks here only answer early. The "Choose files" button is the keyboard
 * and screen-reader way in; outcomes are announced.
 */
export default function FileUpload({
    url,
    extensions,
    maxKb,
    reload,
}: {
    url: string;
    extensions: string[];
    maxKb: number;
    reload: string[];
}) {
    const { t } = useTranslations();
    const helpId = useId();
    const input = useRef<HTMLInputElement>(null);
    const cancel = useRef<(() => void) | null>(null);
    const running = useRef(false);
    // The queue lives in a ref (read by the upload loop) and is mirrored to
    // state for rendering; updaters stay pure under Strict Mode.
    const queue = useRef<Item[]>([]);
    const [items, setItems] = useState<Item[]>([]);

    const setQueue = (change: (current: Item[]) => Item[]) => {
        queue.current = change(queue.current);
        setItems(queue.current);
    };
    const [dragging, setDragging] = useState(false);
    const [announcement, setAnnouncement] = useState('');

    const update = (id: string, change: Partial<Item>) =>
        setQueue((current) =>
            current.map((item) =>
                item.id === id ? { ...item, ...change } : item,
            ),
        );

    const check = (file: File): string | undefined => {
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

        if (!extensions.includes(extension)) {
            return t('This type of file is not accepted.');
        }

        if (file.size > maxKb * 1024) {
            return t('The file is larger than :max KB.', { max: maxKb });
        }

        return undefined;
    };

    // One upload at a time: an Inertia visit replaces the one before it.
    const next = () => {
        const item = queue.current.find(
            (candidate) => candidate.status === 'queued',
        );

        if (!item) {
            running.current = false;

            return;
        }

        running.current = true;
        update(item.id, { status: 'uploading', progress: 0 });

        router.post(
            url,
            { file: item.file },
            {
                forceFormData: true,
                only: reload,
                preserveState: true,
                preserveScroll: true,
                onCancelToken: (token) => {
                    cancel.current = () => token.cancel();
                },
                onProgress: (progress) =>
                    update(item.id, {
                        progress: Math.round(progress?.percentage ?? 0),
                    }),
                onSuccess: () => {
                    update(item.id, { status: 'done', progress: 100 });
                    setAnnouncement(
                        t(':name uploaded.', { name: item.file.name }),
                    );
                },
                onError: (errors) => {
                    update(item.id, {
                        status: 'failed',
                        error: errors.file ?? t('The upload failed.'),
                    });
                    setAnnouncement(
                        t(':name was not uploaded.', { name: item.file.name }),
                    );
                },
                onCancel: () => update(item.id, { status: 'cancelled' }),
                onFinish: () => {
                    cancel.current = null;
                    next();
                },
            },
        );
    };

    const add = (files: FileList | File[]) => {
        const added: Item[] = Array.from(files).map((file) => {
            const error = check(file);

            return {
                id: crypto.randomUUID(),
                file,
                status: error ? 'failed' : 'queued',
                progress: 0,
                error,
            };
        });

        setQueue((current) => [...current, ...added]);

        if (!running.current) {
            next();
        }
    };

    const retry = (id: string) => {
        update(id, { status: 'queued', error: undefined, progress: 0 });

        if (!running.current) {
            next();
        }
    };

    const onDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragging(false);

        if (event.dataTransfer.files.length > 0) {
            add(event.dataTransfer.files);
        }
    };

    return (
        <div className="space-y-4" data-slot="file-upload">
            <div
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                data-test="drop-zone"
                className={cn(
                    'flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors',
                    dragging ? 'border-primary bg-accent' : 'border-input',
                )}
            >
                <Upload className="size-8 text-muted-foreground" aria-hidden />
                <p className="text-sm font-medium">
                    {t('Drop files here, or')}
                </p>
                {/* Fully hidden: the "Choose files" button is the way in, so
                    the input is neither a second tab stop nor an unlabelled
                    control for screen readers. */}
                <input
                    ref={input}
                    type="file"
                    multiple
                    hidden
                    accept={extensions
                        .map((extension) => `.${extension}`)
                        .join(',')}
                    onChange={(event) => {
                        if (event.target.files) {
                            add(event.target.files);
                        }
                        event.target.value = '';
                    }}
                />
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => input.current?.click()}
                    aria-describedby={helpId}
                    data-test="choose-files"
                >
                    {t('Choose files')}
                </Button>
                <p id={helpId} className="text-xs text-muted-foreground">
                    {t('Accepted: :types. Up to :max KB each.', {
                        types: extensions.join(', '),
                        max: maxKb,
                    })}
                </p>
            </div>

            <p aria-live="polite" className="sr-only">
                {announcement}
            </p>

            {items.length > 0 && (
                <ul
                    className="divide-y rounded-xl border"
                    data-slot="upload-queue"
                >
                    {items.map((item) => (
                        <li
                            key={item.id}
                            data-status={item.status}
                            className="flex flex-wrap items-center gap-3 px-4 py-3 text-sm"
                        >
                            {item.status === 'done' && (
                                <CircleCheck
                                    className="size-4 text-success"
                                    aria-hidden
                                />
                            )}
                            {(item.status === 'failed' ||
                                item.status === 'cancelled') && (
                                <CircleAlert
                                    className="size-4 text-destructive-foreground"
                                    aria-hidden
                                />
                            )}
                            <span
                                className="min-w-0 flex-1 truncate font-medium"
                                dir="auto"
                            >
                                {item.file.name}
                            </span>

                            {item.status === 'uploading' && (
                                <>
                                    <progress
                                        max={100}
                                        value={item.progress}
                                        aria-label={t('Uploading :name', {
                                            name: item.file.name,
                                        })}
                                        className="h-2 w-32 overflow-hidden rounded-full accent-primary"
                                    />
                                    <span className="w-10 text-end tabular-nums">
                                        {item.progress}%
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => cancel.current?.()}
                                        aria-label={t(
                                            'Cancel upload of :name',
                                            {
                                                name: item.file.name,
                                            },
                                        )}
                                    >
                                        <X aria-hidden />
                                    </Button>
                                </>
                            )}
                            {item.status === 'queued' && (
                                <span className="text-muted-foreground">
                                    {t('Waiting')}
                                </span>
                            )}
                            {item.status === 'done' && (
                                <span className="text-success">
                                    {t('Uploaded')}
                                </span>
                            )}
                            {(item.status === 'failed' ||
                                item.status === 'cancelled') && (
                                <>
                                    <span className="w-full text-destructive-foreground sm:w-auto">
                                        {item.status === 'cancelled'
                                            ? t('Cancelled')
                                            : item.error}
                                    </span>
                                    {!check(item.file) && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => retry(item.id)}
                                            aria-label={t('Try :name again', {
                                                name: item.file.name,
                                            })}
                                        >
                                            <RotateCcw
                                                className="rtl:-scale-x-100"
                                                aria-hidden
                                            />
                                            {t('Try again')}
                                        </Button>
                                    )}
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
