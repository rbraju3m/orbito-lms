import { Card, Container, Group, Pagination, Stack, Text } from '@mantine/core';
import { IconReceipt } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';

import { formatDateTime } from '@/shared/lib/datetime';
import { formatMinor } from '@/shared/lib/money';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { OrderStatusBadge } from '../components/OrderStatusBadge';
import { ordersQuery } from '../api/queries';

/**
 * Order history.
 *
 * The same endpoint serves staff who hold `order.view.any`, and the server
 * decides which rows they see — this page does not ask for a scope, because a
 * client-supplied one would be a permission check in the wrong place.
 */
export function OrdersRoute() {
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(ordersQuery(page));

  if (isPending) return <LoadingState rows={4} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (data.data.length === 0) {
    return (
      <Container size="md" py="lg">
        <PageHeader title="Orders" />
        <EmptyState
          icon={IconReceipt}
          title="No orders yet"
          description="Courses you buy will show up here with their receipts."
          action={{ label: 'Browse courses', onClick: () => void navigate('/courses') }}
        />
      </Container>
    );
  }

  return (
    <Container size="md" py="lg">
      <PageHeader title="Orders" description="Everything you have bought." />

      <Stack gap="sm">
        {data.data.map((order) => (
          <Card
            key={order.id}
            withBorder
            component={Link}
            to={`/orders/${order.id}`}
            style={{ textDecoration: 'none' }}
          >
            <Group justify="space-between" wrap="nowrap" gap="md">
              <Stack gap={2} style={{ minWidth: 0 }}>
                <Group gap="xs">
                  <Text fw={600}>{order.number}</Text>
                  <OrderStatusBadge status={order.status} label={order.status_label} />
                </Group>
                <Text size="sm" c="dimmed" truncate>
                  {/* The snapshot, so a renamed course does not rewrite a receipt. */}
                  {order.items?.map((item) => item.title).join(', ') ?? ''}
                </Text>
              </Stack>

              <Stack gap={2} align="flex-end">
                <Text fw={600} style={{ whiteSpace: 'nowrap' }}>
                  {formatMinor(order.total_minor, order.currency)}
                </Text>
                <Text size="xs" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
                  {order.placed_at ? formatDateTime(order.placed_at) : '—'}
                </Text>
              </Stack>
            </Group>
          </Card>
        ))}

        {data.meta.last_page > 1 && (
          <Group justify="center" mt="md">
            <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
          </Group>
        )}
      </Stack>
    </Container>
  );
}
