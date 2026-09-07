import { Badge, Tooltip } from '@mantine/core';

import { formatDate } from '@/shared/lib/datetime';

import type { Enrollment } from '../api/types';

const COLOURS: Record<string, string> = {
  active: 'green',
  completed: 'blue',
  expired: 'gray',
  suspended: 'orange',
  cancelled: 'red',
};

/**
 * The status the SERVER stored, with one correction the server itself makes on
 * every request: expiry is evaluated live, so a row can still say `active`
 * while its date has passed and access is already closed. Showing the stored
 * value alone would tell an instructor the opposite of what the learner sees.
 */
export function EnrollmentStatusBadge({ enrollment }: { enrollment: Enrollment }) {
  const lapsed = enrollment.status === 'active' && !enrollment.is_active;

  const label = lapsed ? 'Expired' : enrollment.status_label;
  const colour = lapsed ? 'gray' : (COLOURS[enrollment.status] ?? 'gray');

  const reason =
    enrollment.suspended_reason ??
    (enrollment.expires_at ? `Access ends ${formatDate(enrollment.expires_at)}` : null);

  const badge = (
    <Badge color={colour} variant="light" size="sm">
      {label}
    </Badge>
  );

  return reason ? (
    <Tooltip label={reason} withArrow>
      {badge}
    </Tooltip>
  ) : (
    badge
  );
}
