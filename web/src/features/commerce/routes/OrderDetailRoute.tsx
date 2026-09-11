import {
  Alert,
  Anchor,
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
import { RefundPanel } from '../components/RefundPanel';
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
                <Group gap="xs" wrap="nowrap" style={{ minWidth: 0 }}>
                  <Text truncate>{item.title}</Text>
                  {/*
                    A download line points at where the file LIVES. The order
                    line carries no slug, and does not need one: the library
                    lists everything this reader owns.
                  */}
                  {item.purchasable_type === 'download' && order.status === 'paid' ? (
                    <Anchor component={Link} to="/my-downloads" size="sm" style={{ whiteSpace: 'nowrap' }}>
                      Download
                    </Anchor>
                  ) : null}
                </Group>
                <Text fw={500} style={{ whiteSpace: 'nowrap' }}>
                  {formatMinor(item.total_minor, order.currency)}
                </Text>
              </Group>
            </div>
          ))}

          <Divider />

          {/* What the coupon took off, as frozen on the order at checkout. */}
          {order.discount_minor > 0 ? (
            <Stack gap={4} px="md" pt="md">
              <Group justify="space-between">
                <Text size="sm" c="dimmed">
                  Subtotal
                </Text>
                <Text size="sm">{formatMinor(order.subtotal_minor, order.currency)}</Text>
              </Group>
              <Group justify="space-between">
                <Text size="sm" c="dimmed">
                  Discount{order.coupon_code ? ` (${order.coupon_code})` : ''}
                </Text>
                <Text size="sm" c="green.7">
                  −{formatMinor(order.discount_minor, order.currency)}
                </Text>
              </Group>
            </Stack>
          ) : null}

          <Group justify="space-between" p="md">
            <Text c="dimmed">Total</Text>
            <Title order={4}>{formatMinor(order.total_minor, order.currency)}</Title>
          </Group>

          {order.refunded_minor > 0 ? (
            <Group justify="space-between" px="md" pb="md">
              <Text size="sm" c="dimmed">
                Refunded
              </Text>
              <Text size="sm">−{formatMinor(order.refunded_minor, order.currency)}</Text>
            </Group>
          ) : null}
        </Card>

        <RefundPanel order={order} />

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
                       * Stripe hands back its hosted Checkout page and the
                       * learner pays there. Stripe sends them back here, where
                       * the poll above picks up the webhook — the return itself
                       * proves nothing (ADR-05); only the webhook grants. A
                       * gateway with no page to visit (the test gateway)
                       * returns no URL, and the order waits the same way.
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
