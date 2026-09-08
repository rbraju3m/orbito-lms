import { Alert, Button, Card, Container, Divider, Group, Stack, Text, Title } from '@mantine/core';
import { IconAlertTriangle, IconShoppingCart, IconTrash } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { formatMinor } from '@/shared/lib/money';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { cartQuery, useCheckout, useClearCart, useRemoveCartLine } from '../api/queries';
import type { CartLine } from '../api/types';

/**
 * The basket.
 *
 * The total shown here is the server's `estimated_total_minor` and is labelled
 * an estimate, because that is what it is: the basket is priced live on every
 * read while the order is priced once, at checkout. If a sale ends in between,
 * the two legitimately differ — so this page must not present its figure as a
 * promise, and it does not compute one of its own.
 */
export function CartRoute() {
  const navigate = useNavigate();
  const { data: cart, isPending, isError, error, refetch } = useQuery(cartQuery());

  const removeLine = useRemoveCartLine();
  const clearCart = useClearCart();
  const checkout = useCheckout();

  const checkoutError = checkout.error instanceof ApiError ? checkout.error : null;

  if (isPending) return <LoadingState rows={3} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  if (cart.items.length === 0) {
    return (
      <Container size="md" py="lg">
        <PageHeader title="Your basket" />
        <EmptyState
          icon={IconShoppingCart}
          title="Your basket is empty"
          description="Courses you add will appear here until you check out."
          action={{ label: 'Browse courses', onClick: () => void navigate('/courses') }}
        />
      </Container>
    );
  }

  const unavailable = cart.items.filter((line) => !line.is_available);

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Your basket"
        description={`${cart.item_count} ${cart.item_count === 1 ? 'course' : 'courses'}`}
        actions={
          <Button
            variant="subtle"
            color="gray"
            loading={clearCart.isPending}
            onClick={() => clearCart.mutate()}
          >
            Empty basket
          </Button>
        }
      />

      <Stack gap="md">
        <Card withBorder padding="0">
          {cart.items.map((line, index) => (
            <div key={line.id}>
              {index > 0 && <Divider />}
              <CartLineRow
                line={line}
                currency={cart.currency}
                removing={removeLine.isPending && removeLine.variables === line.id}
                onRemove={() => removeLine.mutate(line.id)}
              />
            </div>
          ))}
        </Card>

        {/*
         * A line that cannot be bought blocks the whole basket, because
         * checkout is all-or-nothing on the server. Saying so here beats a
         * 409 after the button.
         */}
        {unavailable.length > 0 && (
          <Alert color="yellow" icon={<IconAlertTriangle size={16} />}>
            {unavailable.length === 1
              ? `“${unavailable[0]?.title}” is no longer for sale. Remove it to continue.`
              : `${unavailable.length} courses are no longer for sale. Remove them to continue.`}
          </Alert>
        )}

        {checkoutError && (
          <Alert
            color={checkoutError.isSubscriptionLapsed ? 'yellow' : 'red'}
            icon={<IconAlertTriangle size={16} />}
          >
            {checkoutError.message}
          </Alert>
        )}

        <Card withBorder>
          <Group justify="space-between" align="baseline">
            <Text c="dimmed">Estimated total</Text>
            <Title order={3}>{formatMinor(cart.estimated_total_minor, cart.currency)}</Title>
          </Group>

          <Text size="xs" c="dimmed" mt={4}>
            Prices are confirmed when you check out.
          </Text>

          <Button
            mt="md"
            fullWidth
            size="md"
            /* The server decides this, so the button cannot disagree with it. */
            disabled={!cart.is_checkoutable}
            loading={checkout.isPending}
            onClick={() =>
              checkout.mutate(undefined, {
                onSuccess: (order) => void navigate(`/orders/${order.id}`),
              })
            }
          >
            Check out
          </Button>
        </Card>
      </Stack>
    </Container>
  );
}

function CartLineRow({
  line,
  currency,
  removing,
  onRemove,
}: {
  line: CartLine;
  currency: string;
  removing: boolean;
  onRemove: () => void;
}) {
  return (
    <Group justify="space-between" wrap="nowrap" p="md" gap="md">
      <Stack gap={2} style={{ minWidth: 0 }}>
        <Text fw={500} truncate>
          {line.title}
        </Text>
        {!line.is_available && (
          <Text size="xs" c="yellow.7">
            No longer for sale
          </Text>
        )}
      </Stack>

      <Group gap="sm" wrap="nowrap">
        <Text fw={600} style={{ whiteSpace: 'nowrap' }}>
          {/* Null is unavailable, never free. Rendering 0 here would be a lie. */}
          {line.amount_minor === null ? '—' : formatMinor(line.amount_minor, currency)}
        </Text>
        <Button
          variant="subtle"
          color="red"
          size="compact-sm"
          loading={removing}
          onClick={onRemove}
          aria-label={`Remove ${line.title} from your basket`}
        >
          <IconTrash size={16} />
        </Button>
      </Group>
    </Group>
  );
}
