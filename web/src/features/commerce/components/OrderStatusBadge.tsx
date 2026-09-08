import { Badge } from '@mantine/core';

import type { OrderStatus } from '../api/types';

/**
 * `awaiting_payment` is blue rather than green: money may be in flight, and
 * the webhook that settles it arrives out of band. Colouring it as success
 * would tell a learner they had bought something they had not.
 */
const COLOURS: Record<OrderStatus, string> = {
  pending: 'gray',
  awaiting_payment: 'blue',
  paid: 'green',
  cancelled: 'gray',
  failed: 'red',
};

export function OrderStatusBadge({ status, label }: { status: OrderStatus; label: string }) {
  return (
    <Badge color={COLOURS[status] ?? 'gray'} variant="light">
      {label}
    </Badge>
  );
}
