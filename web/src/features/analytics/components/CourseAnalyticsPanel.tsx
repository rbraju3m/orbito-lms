import { Button, Card, Group, SimpleGrid, Stack, Title } from '@mantine/core';
import { IconDownload } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { formatMinor } from '@/shared/lib/money';
import { ErrorState, LoadingState } from '@/shared/ui';

import { courseAnalyticsQuery, useCsvExport } from '../api/queries';
import { FunnelHeatmap } from './FunnelHeatmap';
import { KpiTile } from './KpiTile';
import { RangePicker } from './RangePicker';
import { rangeFor, type RangePreset } from '../lib/range';
import { TrendChart } from './TrendChart';

/**
 * One course's numbers, inside the studio editor.
 *
 * A tab rather than its own route, like the rest of the editor: an author
 * moving between panels should not lose a scroll position and refetch a
 * course. The FUNNEL is the reason this panel exists — the trend is the
 * context that makes a stall legible.
 */
export function CourseAnalyticsPanel({ courseId }: { courseId: string }) {
  const [preset, setPreset] = useState<RangePreset>('30');
  const { from, to } = rangeFor(preset);
  const { data, isPending, isError, error, refetch } = useQuery(
    courseAnalyticsQuery(courseId, from, to),
  );
  const csv = useCsvExport();

  if (isPending) return <LoadingState rows={3} height={80} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return (
    <Stack gap="lg">
      <Group justify="space-between">
        <RangePicker value={preset} onChange={setPreset} />
        <Button
          size="compact-sm"
          variant="light"
          leftSection={<IconDownload size={14} />}
          loading={csv.isPending}
          onClick={() =>
            csv.mutate({
              path: `/analytics/courses/${courseId}/export`,
              filename: `orbito-funnel-${data.course.slug}.csv`,
            })
          }
        >
          Export funnel
        </Button>
      </Group>

      <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }} spacing="md">
        <KpiTile label="Views" value={data.totals.views} />
        <KpiTile label="Enrolments" value={data.totals.enrollments} />
        <KpiTile label="Completions" value={data.totals.completions} />
        <KpiTile
          label="Revenue"
          value={data.totals.revenue_minor}
          format={(value) => formatMinor(value, data.currency)}
        />
      </SimpleGrid>

      <Card withBorder>
        <Stack gap="sm">
          <Title order={4}>Activity</Title>
          <TrendChart
            labels={data.series.map((point) => point.date)}
            series={[
              {
                label: 'Views',
                values: data.series.map((point) => point.views),
                colour: 'orbito',
              },
              {
                label: 'Enrolments',
                values: data.series.map((point) => point.enrollments),
                colour: 'success',
              },
            ]}
          />
        </Stack>
      </Card>

      <Card withBorder>
        <Stack gap="sm">
          <Title order={4}>Where learners stall</Title>
          <FunnelHeatmap courseId={courseId} />
        </Stack>
      </Card>
    </Stack>
  );
}
