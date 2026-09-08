import { Button, Card, Group, SimpleGrid, Stack, Table, Text, Title } from '@mantine/core';
import { IconChartBar, IconDownload } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';

import { formatMinor } from '@/shared/lib/money';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { overviewQuery, useCsvExport } from '../api/queries';
import { KpiTile } from '../components/KpiTile';
import { RangePicker } from '../components/RangePicker';
import { rangeFor, type RangePreset } from '../lib/range';
import { TrendChart } from '../components/TrendChart';

/**
 * The academy dashboard.
 *
 * Every figure here is a ROLLUP (ADR-08) — nothing on this page reads the
 * event log, the enrolments table or the orders ledger, which is why it costs
 * the same whether the academy is a week or a decade old.
 */
export function AnalyticsOverviewRoute() {
  const [preset, setPreset] = useState<RangePreset>('30');
  const { from, to } = rangeFor(preset);
  const { data, isPending, isError, error, refetch } = useQuery(overviewQuery(from, to));
  const csv = useCsvExport();

  if (isPending) return <LoadingState rows={4} height={90} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const labels = data.series.map((point) => point.date);
  const hasActivity = data.series.some(
    (point) => point.new_enrollments > 0 || point.completions > 0 || point.revenue_minor > 0,
  );

  return (
    <>
      <PageHeader
        title="Analytics"
        description={`${data.range.from} to ${data.range.to} · ${data.range.timezone}`}
        actions={
          <Group gap="xs">
            <RangePicker value={preset} onChange={setPreset} />
            <Button
              variant="light"
              leftSection={<IconDownload size={16} />}
              loading={csv.isPending}
              onClick={() =>
                csv.mutate({
                  path: `/analytics/export/platform?from=${from}&to=${to}`,
                  filename: `orbito-platform-${from}-to-${to}.csv`,
                })
              }
            >
              CSV
            </Button>
          </Group>
        }
      />

      <Stack gap="lg">
        <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }} spacing="md">
          <KpiTile
            label="Enrolments"
            value={data.totals.new_enrollments}
            previous={data.previous.new_enrollments}
          />
          <KpiTile
            label="Revenue"
            value={data.totals.revenue_minor}
            previous={data.previous.revenue_minor}
            format={(value) => formatMinor(value, data.currency)}
          />
          <KpiTile
            label="Completions"
            value={data.totals.completions}
            previous={data.previous.completions}
          />
          <KpiTile
            label="Busiest day"
            value={data.totals.peak_daily_active}
            previous={data.previous.peak_daily_active}
            // Named for what it is. Distinct people cannot be summed across
            // days without counting a regular five times over.
            hint="Most active learners in one day"
          />
        </SimpleGrid>

        <Card withBorder>
          <Stack gap="sm">
            <Title order={4}>Activity</Title>
            <TrendChart
              labels={labels}
              series={[
                {
                  label: 'Enrolments',
                  values: data.series.map((point) => point.new_enrollments),
                  colour: 'orbito',
                },
                {
                  label: 'Completions',
                  values: data.series.map((point) => point.completions),
                  colour: 'success',
                },
              ]}
            />
          </Stack>
        </Card>

        <Card withBorder>
          <Stack gap="sm">
            <Group justify="space-between">
              <Title order={4}>Top courses</Title>
              <Button
                size="compact-xs"
                variant="subtle"
                leftSection={<IconDownload size={14} />}
                loading={csv.isPending}
                onClick={() =>
                  csv.mutate({
                    path: `/analytics/export/courses?from=${from}&to=${to}`,
                    filename: `orbito-courses-${from}-to-${to}.csv`,
                  })
                }
              >
                CSV
              </Button>
            </Group>

            {data.top_courses.length === 0 ? (
              <EmptyState
                icon={IconChartBar}
                title={hasActivity ? 'No course activity in this range' : 'Nothing to report yet'}
                description={
                  hasActivity
                    ? 'Try a longer range.'
                    : 'Figures appear the day after the first enrolment — rollups are built nightly.'
                }
              />
            ) : (
              <Table.ScrollContainer minWidth={520}>
                <Table verticalSpacing="xs" highlightOnHover>
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>Course</Table.Th>
                      <Table.Th>Views</Table.Th>
                      <Table.Th>Enrolments</Table.Th>
                      <Table.Th>Completions</Table.Th>
                      <Table.Th>Revenue</Table.Th>
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {data.top_courses.map((row) => (
                      <Table.Tr key={row.course.id}>
                        <Table.Td>
                          <Text
                            component={Link}
                            to={`/studio/courses/${row.course.id}`}
                            size="sm"
                            fw={500}
                          >
                            {row.course.title}
                          </Text>
                        </Table.Td>
                        <Table.Td>{row.views}</Table.Td>
                        <Table.Td>{row.enrollments}</Table.Td>
                        <Table.Td>{row.completions}</Table.Td>
                        <Table.Td>{formatMinor(row.revenue_minor, data.currency)}</Table.Td>
                      </Table.Tr>
                    ))}
                  </Table.Tbody>
                </Table>
              </Table.ScrollContainer>
            )}
          </Stack>
        </Card>
      </Stack>
    </>
  );
}
