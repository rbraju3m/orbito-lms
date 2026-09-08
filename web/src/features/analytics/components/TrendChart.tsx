import { Box, Group, Text } from '@mantine/core';
import { useId } from 'react';

export interface TrendSeries {
  label: string;
  /** One number per point, aligned with `labels`. */
  values: number[];
  /** A Mantine colour token — resolved to a CSS variable, so it themes. */
  colour: string;
}

/**
 * A small multi-series area chart, hand-rolled in SVG.
 *
 * NO CHART LIBRARY, deliberately. Recharts and its peers are 90–150 KB
 * gzipped, and this is the only screen in the product that draws a line —
 * Phase 11 already established that a lazy route does not keep a shared
 * dependency off the first-paint path. Ninety lines of SVG is cheaper to ship
 * and cheaper to read than a dependency that renders one chart.
 *
 * It is drawn in a `viewBox` with no fixed width, so it scales with its
 * container rather than needing a resize observer.
 *
 * ACCESSIBILITY: an SVG is not a chart to a screen reader. The path is
 * `aria-hidden` and the figure is labelled with a plain-language summary; the
 * numbers themselves live in the table beneath it on every screen that uses
 * this.
 */
export function TrendChart({
  labels,
  series,
  height = 180,
  formatValue = (value: number) => String(value),
}: {
  labels: string[];
  series: TrendSeries[];
  height?: number;
  formatValue?: (value: number) => string;
}) {
  const gradientId = useId();
  const width = 640;
  const pad = { top: 8, right: 8, bottom: 18, left: 8 };

  const all = series.flatMap((s) => s.values);
  // A flat zero series still needs a scale, or every point lands on the axis
  // and the chart reads as "no data" rather than "no activity".
  const max = Math.max(1, ...all);
  const count = Math.max(labels.length, 2);

  const x = (index: number) => pad.left + (index * (width - pad.left - pad.right)) / (count - 1);
  const y = (value: number) => pad.top + (1 - value / max) * (height - pad.top - pad.bottom);

  return (
    <Box>
      <svg
        viewBox={`0 0 ${width} ${height}`}
        width="100%"
        height={height}
        role="img"
        aria-label={`${series.map((s) => s.label).join(' and ')} from ${labels[0] ?? ''} to ${labels[labels.length - 1] ?? ''}. Peak ${formatValue(max)}.`}
        style={{ display: 'block', overflow: 'visible' }}
      >
        {/* Three guides, not a grid: a chart this small drowns in one. */}
        {[0, 0.5, 1].map((fraction) => (
          <line
            key={fraction}
            x1={pad.left}
            x2={width - pad.right}
            y1={y(max * fraction)}
            y2={y(max * fraction)}
            stroke="var(--mantine-color-default-border)"
            strokeWidth={1}
          />
        ))}

        {series.map((s, seriesIndex) => {
          const colour = `var(--mantine-color-${s.colour}-6)`;
          const points = s.values.map((value, index) => `${x(index)},${y(value)}`);

          return (
            <g key={s.label} aria-hidden>
              <defs>
                <linearGradient id={`${gradientId}-${seriesIndex}`} x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stopColor={colour} stopOpacity={0.28} />
                  <stop offset="100%" stopColor={colour} stopOpacity={0} />
                </linearGradient>
              </defs>

              <polygon
                points={`${pad.left},${y(0)} ${points.join(' ')} ${x(s.values.length - 1)},${y(0)}`}
                fill={`url(#${gradientId}-${seriesIndex})`}
              />
              <polyline
                points={points.join(' ')}
                fill="none"
                stroke={colour}
                strokeWidth={2}
                strokeLinejoin="round"
                strokeLinecap="round"
              />
            </g>
          );
        })}
      </svg>

      <Group gap="md" mt="xs" justify="center">
        {series.map((s) => (
          <Group key={s.label} gap={6}>
            <Box
              w={10}
              h={10}
              style={{
                borderRadius: 2,
                background: `var(--mantine-color-${s.colour}-6)`,
              }}
            />
            <Text size="xs" c="dimmed">
              {s.label}
            </Text>
          </Group>
        ))}
      </Group>
    </Box>
  );
}
