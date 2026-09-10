import { Alert, Badge, Card, Group, Progress, Stack, Text } from '@mantine/core';
import { IconAlertTriangle, IconInfoCircle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { academyUsageQuery, type AcademyUsage, type LimitRow } from '../api/usage';

/**
 * What this academy has used, against what its plan allows.
 *
 * Read-only on purpose. An academy cannot change its own plan from here —
 * that is the platform operator's action, and inventing a self-serve upgrade
 * button before there is a billing flow behind it would be a dead end. What
 * this screen owes its reader is the number and the reason, before they meet
 * it as a 402 halfway through creating something.
 */
export function PlanUsageRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(academyUsageQuery());

  if (isPending) return <LoadingState rows={4} height={72} label="Loading plan usage" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <PlanUsage usage={data} />;
}

function PlanUsage({ usage }: { usage: AcademyUsage }) {
  return (
    <>
      <PageHeader
        title="Plan and usage"
        description={
          usage.plan
            ? `This academy is on ${usage.plan.name}.`
            : 'This academy is not on a plan.'
        }
      />

      <Stack gap="md" maw={720}>
        {usage.any_at_limit ? (
          <Alert color="warning" icon={<IconAlertTriangle size={16} />} role="alert">
            This academy has reached a limit on its plan. Anything marked below will be refused
            until the plan changes or something is removed. Your platform administrator can move
            you onto a larger plan.
          </Alert>
        ) : null}

        {usage.limits.length === 0 ? (
          <Card withBorder>
            <Text size="sm" c="dimmed">
              Nothing on this plan is metered.
            </Text>
          </Card>
        ) : (
          <Card withBorder>
            <Stack gap="lg">
              {usage.limits.map((row) => (
                <LimitMeter key={row.metric} row={row} />
              ))}
            </Stack>
          </Card>
        )}

        {/*
          `note`, not Mantine's default `alert`: this is a standing
          explanation, and a live region that announces itself on every render
          teaches a screen-reader user to ignore the one that matters.
        */}
        <Alert color="gray" icon={<IconInfoCircle size={16} />} variant="light" role="note">
          Student and storage figures are counted and shown here, but never block anybody. A
          learner enrolling has no way to change their academy&rsquo;s plan, so they are never
          turned away for it.
        </Alert>
      </Stack>
    </>
  );
}

function LimitMeter({ row }: { row: LimitRow }) {
  const used = row.is_bytes ? formatBytes(row.used) : row.used.toLocaleString();
  const cap = row.limit === null ? null : row.is_bytes ? formatBytes(row.limit) : row.limit.toLocaleString();

  // Over > at > near. Only an ENFORCED cap is coloured as a stop; an
  // unenforced one that is full is information, not an obstacle.
  const tone = !row.enforced ? 'gray' : row.over_limit || row.at_limit ? 'danger' : 'orbito';

  return (
    <Stack gap={6}>
      {/* Wraps at 360px: the label, its badge and the figure are three
          things that do not fit on one narrow line. */}
      <Group justify="space-between" gap="sm">
        <Group gap="xs">
          <Text size="sm" fw={600}>
            {row.label}
          </Text>
          {row.enforced && (row.at_limit || row.over_limit) ? (
            <Badge size="sm" color="danger" variant="light">
              {row.over_limit ? 'Over limit' : 'At limit'}
            </Badge>
          ) : null}
          {!row.enforced ? (
            <Badge size="sm" color="gray" variant="light">
              Counted only
            </Badge>
          ) : null}
        </Group>

        <Text size="sm" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
          {cap === null ? `${used} — unlimited` : `${used} of ${cap}`}
        </Text>
      </Group>

      {/*
        No bar for an uncapped metric: a progress bar with no end implies one,
        and an empty track reads as "none used" rather than "no limit".
      */}
      {row.fraction === null ? null : (
        <Progress
          value={row.fraction * 100}
          color={tone}
          size="sm"
          aria-label={`${row.label}: ${used} of ${cap ?? 'unlimited'}`}
        />
      )}
    </Stack>
  );
}

/** Binary units, one decimal, because a plan is sold in GB. */
function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;

  const units = ['KB', 'MB', 'GB', 'TB'];
  let value = bytes / 1024;
  let unit = 0;

  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit += 1;
  }

  return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}
