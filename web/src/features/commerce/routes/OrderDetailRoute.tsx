import {
  Alert,
  Button,
  Card,
  Container,
  Divider,
  Group,
  Select,
  Stack,
  Text,
  Title,
} from '@mantine/core';
import { IconAlertTriangle, IconCircleCheck, IconClock } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useParams } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/lib/datetime';
import { formatMinor } from '@/shared/lib/money';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { OrderStatusBadge } from '../components/OrderStatusBadge';
import { orderQuery, usePayOrder } from '../api/queries';

/**
 * Gateways a learner may choose. Only those the API implements — the enum
 * declares more so the column never widens, but the rest are refused.
 */
const GATEWAYS = [
  { value: 'stripe', label: 'Card (Stripe)' },
  { value: 'fake', label: 'Test gateway' },
];

/**
 * One order, and the only place a payment starts.
 *
 * The page NEVER marks anything paid, because the API offers no way to: a
 * redirect back from a provider proves nothing, so access waits on a verified
 * webhook the browser never sees (ADR-05). What this page does instead is
 * poll while an order is awaiting payment, so the state arrives on its own.
 */
export function OrderDetailRoute() {
  const { id = '' } = useParams();
  const [gateway, setGateway] = useState<string>('stripe');

  const {
    data: order,
    isPending,
    isError,
    error,
    refetch,
  } = useQuery({
    ...orderQuery(id),
    /*
     * The webhook can land at any moment, and nothing in the browser will be
     * told. Polling only while the answer can still change keeps a paid or
     * cancelled order from costing a request every few seconds forever.
     */
    refetchInterval: (query) => (query.state.data?.status === 'awaiting_payment' ? 5_000 : false),
  });

  const pay = usePayOrder(id);
  const payError = pay.error instanceof ApiError ? pay.error : null;

  if (isPending) return <LoadingState rows={3} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const payable = order.status === 'pending' || order.status === 'awaiting_payment';

  return (
    <Container size="md" py="lg">
      <PageHeader
        title={`Order ${order.number}`}
        description={order.placed_at ? `Placed ${formatDateTime(order.placed_at)}` : undefined}
        actions={<OrderStatusBadge status={order.status} label={order.status_label} />}
      />

      <Stack gap="md">
        {order.status === 'paid' && (
          <Alert color="green" icon={<IconCircleCheck size={16} />}>
            <Group justify="space-between" wrap="nowrap">
              <Text size="sm">
                Paid{order.paid_at ? ` on ${formatDateTime(order.paid_at)}` : ''}. Your courses are
                ready.
              </Text>
              <Button component={Link} to="/dashboard/courses" variant="light" size="compact-sm">
                Start learning
              </Button>
            </Group>
          </Alert>
        )}

        {order.status === 'awaiting_payment' && (
          <Alert color="blue" icon={<IconClock size={16} />}>
            Waiting for your payment to be confirmed. This page will update on its own — you do not
            need to refresh it.
          </Alert>
        )}

        <Card withBorder padding="0">
          {(order.items ?? []).map((item, index) => (
            <div key={`${item.purchasable_type}-${item.purchasable_id}`}>
              {index > 0 && <Divider />}
              <Group justify="space-between" p="md" wrap="nowrap" gap="md">
                {/* The snapshot: editing the course later must not rewrite this. */}
                <Text truncate>{item.title}</Text>
                <Text fw={500} style={{ whiteSpace: 'nowrap' }}>
                  {formatMinor(item.total_minor, order.currency)}
                </Text>
              </Group>
            </div>
          ))}

          <Divider />

          <Group justify="space-between" p="md">
            <Text c="dimmed">Total</Text>
            <Title order={4}>{formatMinor(order.total_minor, order.currency)}</Title>
          </Group>
        </Card>

        {payError && (
          <Alert
            color={payError.isBillingBlocked ? 'yellow' : 'red'}
            icon={<IconAlertTriangle size={16} />}
          >
            {payError.message}
          </Alert>
        )}

        {payable && (
          <Card withBorder>
            <Stack gap="sm">
              <Select
                label="Pay with"
                data={GATEWAYS}
                value={gateway}
                onChange={(value) => setGateway(value ?? 'stripe')}
                allowDeselect={false}
              />

              <Button
                size="md"
                loading={pay.isPending}
                onClick={() =>
                  pay.mutate(gateway, {
                    onSuccess: (handoff) => {
                      /*
                       * A hosted-checkout provider sends the learner away. One
                       * with an inline SDK returns a client secret instead,
                       * which nothing here can use yet — so the order simply
                       * sits in `awaiting_payment` and the poll above picks up
                       * the webhook. Silently doing nothing would be worse
                       * than saying so, hence the note below.
                       */
                      if (handoff.redirect_url) {
                        window.location.assign(handoff.redirect_url);
                      }
                    },
                  })
                }
              >
                {order.status === 'awaiting_payment' ? 'Try paying again' : 'Pay now'}
              </Button>

              <Text size="xs" c="dimmed">
                You will be taken to the payment provider. Your access opens once they confirm the
                payment, which can take a few seconds.
              </Text>
            </Stack>
          </Card>
        )}
      </Stack>
    </Container>
  );
}
