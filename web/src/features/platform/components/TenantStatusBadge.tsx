import { Badge } from '@mantine/core';

import type { TenantStatus } from '../api/types';

/**
 * One colour per lifecycle state, used identically on the list and the detail
 * so an operator scanning thirty rows learns the mapping once.
 *
 * The LABEL comes from the server (`status_label`) rather than a lookup here:
 * the enum owns its own wording, and a second copy in TypeScript is a second
 * thing to remember when it changes.
 */
const COLOR: Record<TenantStatus, string> = {
  pending: 'warning',
  active: 'success',
  suspended: 'danger',
  // Grey, not red. A rejected signup is a closed decision, not an alarm.
  rejected: 'gray',
};

export function TenantStatusBadge({ status, label }: { status: TenantStatus; label: string }) {
  return (
    <Badge variant="light" color={COLOR[status]}>
      {label}
    </Badge>
  );
}
