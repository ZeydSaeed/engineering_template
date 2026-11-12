import { useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/hooks/use-translations';

export type BarDatum = {
    key: string;
    // Axis and table label, already translated and formatted.
    label: string;
    value: number;
};

const WIDTH = 640;
const HEIGHT = 240;
const PAD = { top: 16, end: 12, bottom: 32, start: 40 };
const MAX_BAR = 24;

/** Clean tick values: 0 and up to four steps of 1, 2 or 5 x 10^n. */
function ticks(max: number): number[] {
    if (max <= 0) {
        return [0, 1];
    }

    const raw = max / 4;
    const power = 10 ** Math.floor(Math.log10(raw));
    const step =
        [1, 2, 5, 10].map((m) => m * power).find((s) => s >= raw) ?? raw;

    return Array.from(
        { length: Math.ceil(max / step) + 1 },
        (_, i) => i * step,
    );
}

/**
 * A single-series column chart (improvement #21): one colour (the primary
 * token, contrast checked by DesignTokensTest), columns at most 24 px with
 * a 4 px rounded end on a single baseline, hairline grid, no legend (the
 * title names the series). Every column is reachable by keyboard and named
 * with its value; hover or focus shows it; a table view carries every
 * number. In right-to-left languages the axis runs from the right.
 */
export default function BarChart({
    title,
    description,
    data,
    format = (value) => String(value),
    labelEvery = 1,
}: {
    title: string;
    description?: string;
    data: BarDatum[];
    format?: (value: number) => string;
    labelEvery?: number;
}) {
    const { t, direction } = useTranslations();
    const id = useId();
    const [table, setTable] = useState(false);
    const [active, setActive] = useState<number | null>(null);

    const max = Math.max(0, ...data.map((d) => d.value));
    const scale = ticks(max);
    const top = scale[scale.length - 1];
    const plotWidth = WIDTH - PAD.start - PAD.end;
    const plotHeight = HEIGHT - PAD.top - PAD.bottom;
    const band = plotWidth / Math.max(1, data.length);
    const bar = Math.max(2, Math.min(MAX_BAR, band - 2));
    const rtl = direction === 'rtl';

    const x = (index: number) => {
        const centre = PAD.start + band * index + band / 2;

        return rtl ? WIDTH - centre : centre;
    };
    const y = (value: number) =>
        PAD.top + plotHeight - (value / top) * plotHeight;

    return (
        <figure className="space-y-3" data-slot="bar-chart">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <figcaption>
                    <h2 id={`${id}-title`} className="text-base font-semibold">
                        {title}
                    </h2>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </figcaption>
                <Button
                    variant="outline"
                    size="sm"
                    aria-pressed={table}
                    onClick={() => setTable(!table)}
                    data-test="chart-table-toggle"
                >
                    {t('Show as table')}
                </Button>
            </div>

            {table ? (
                <div className="overflow-x-auto rounded-xl border">
                    <table className="w-full text-sm" data-slot="chart-table">
                        <caption className="sr-only">{title}</caption>
                        <thead className="bg-muted/50">
                            <tr>
                                <th
                                    scope="col"
                                    className="px-4 py-2 text-start font-medium"
                                >
                                    {t('Item')}
                                </th>
                                <th
                                    scope="col"
                                    className="px-4 py-2 text-end font-medium"
                                >
                                    {t('Value')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.map((datum) => (
                                <tr key={datum.key} className="border-t">
                                    <th
                                        scope="row"
                                        className="px-4 py-2 text-start font-normal"
                                    >
                                        {datum.label}
                                    </th>
                                    <td className="px-4 py-2 text-end tabular-nums">
                                        {format(datum.value)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <div className="relative">
                    <svg
                        viewBox={`0 0 ${WIDTH} ${HEIGHT}`}
                        role="group"
                        aria-labelledby={`${id}-title`}
                        className="h-auto w-full overflow-visible"
                    >
                        {scale.map((tick) => (
                            <g key={tick} aria-hidden>
                                <line
                                    x1={PAD.start}
                                    x2={WIDTH - PAD.end}
                                    y1={y(tick)}
                                    y2={y(tick)}
                                    className="stroke-border"
                                    strokeWidth={1}
                                />
                                <text
                                    x={
                                        rtl
                                            ? WIDTH - PAD.start + 6
                                            : PAD.start - 6
                                    }
                                    y={y(tick)}
                                    textAnchor={rtl ? 'start' : 'end'}
                                    dominantBaseline="middle"
                                    className="fill-muted-foreground text-[11px]"
                                >
                                    {format(tick)}
                                </text>
                            </g>
                        ))}

                        {data.map((datum, index) => {
                            const height = Math.max(0, y(0) - y(datum.value));
                            const left = x(index) - bar / 2;
                            const r = Math.min(4, bar / 2, height);
                            const base = y(0);

                            return (
                                <g
                                    key={datum.key}
                                    role="img"
                                    tabIndex={0}
                                    aria-label={`${datum.label}: ${format(datum.value)}`}
                                    onMouseEnter={() => setActive(index)}
                                    onMouseLeave={() => setActive(null)}
                                    onFocus={() => setActive(index)}
                                    onBlur={() => setActive(null)}
                                    className="focus:outline-none [&:focus-visible>rect:last-child]:stroke-ring"
                                    data-bar={datum.key}
                                >
                                    {/* A hit target wider and taller than the column. */}
                                    <rect
                                        x={x(index) - band / 2}
                                        y={PAD.top}
                                        width={band}
                                        height={plotHeight}
                                        fill="transparent"
                                    />
                                    {height > 0 && (
                                        <path
                                            d={`M${left},${base} V${base - height + r} Q${left},${base - height} ${left + r},${base - height} H${left + bar - r} Q${left + bar},${base - height} ${left + bar},${base - height + r} V${base} Z`}
                                            className={
                                                active === index
                                                    ? 'fill-primary'
                                                    : 'fill-primary/85'
                                            }
                                        />
                                    )}
                                    <rect
                                        x={x(index) - band / 2 + 1}
                                        y={PAD.top}
                                        width={Math.max(0, band - 2)}
                                        height={plotHeight}
                                        fill="none"
                                        strokeWidth={2}
                                        rx={4}
                                    />
                                    {index % labelEvery === 0 && (
                                        <text
                                            x={x(index)}
                                            y={HEIGHT - PAD.bottom + 18}
                                            textAnchor="middle"
                                            aria-hidden
                                            className="fill-muted-foreground text-[11px]"
                                        >
                                            {datum.label}
                                        </text>
                                    )}
                                </g>
                            );
                        })}
                    </svg>

                    {active !== null && data[active] && (
                        <div
                            role="presentation"
                            className="pointer-events-none absolute top-0 z-10 -translate-x-1/2 rounded-md border bg-popover px-2 py-1 text-xs text-popover-foreground shadow-sm"
                            style={{ left: `${(x(active) / WIDTH) * 100}%` }}
                        >
                            <span className="font-medium">
                                {data[active].label}
                            </span>
                            {': '}
                            <span className="tabular-nums">
                                {format(data[active].value)}
                            </span>
                        </div>
                    )}
                </div>
            )}
        </figure>
    );
}
