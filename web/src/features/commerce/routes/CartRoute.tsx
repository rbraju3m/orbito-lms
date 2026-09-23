import { Alert, Button, Card, Container, Divider, Group, Stack, Text, Title } from '@mantine/core';
import { IconAlertTriangle, IconShoppingCart, IconTrash } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { plural, t } from '@/shared/i18n';
import { formatMinor } from '@/shared/lib/money';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { cartQuery, useCheckout, useClearCart, useRemoveCartLine } from '../api/queries';
import type { CartLine } from '../api/types';
import { CouponField } from '../components/CouponField';

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
        <PageHeader title={t('commerce.cart.title', 'Your basket')} />
        <EmptyState
          icon={IconShoppingCart}
          title={t('commerce.cart.empty_title', 'Your basket is empty')}
          description={t(
            'commerce.cart.empty_body',
            'Courses you add will appear here until you check out.',
          )}
          action={{
            label: t('commerce.cart.browse', 'Browse courses'),
            onClick: () => void navigate('/courses'),
          }}
        />
      </Container>
    );
  }

  const unavailable = cart.items.filter((line) => !line.is_available);

  return (
    <Container size="md" py="lg">
      <PageHeader
        title={t('commerce.cart.title', 'Your basket')}
        description={plural('commerce.cart.count', cart.item_count, {
          one: '{count} course',
          other: '{count} courses',
        })}
        actions={
          <Button
            variant="subtle"
            color="gray"
            loading={clearCart.isPending}
            onClick={() => clearCart.mutate()}
          >
            {t('commerce.cart.clear', 'Empty basket')}
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
              ? t(
                  'commerce.cart.unavailable_one',
                  '“{title}” is no longer for sale. Remove it to continue.',
                  { title: unavailable[0]?.title ?? '' },
                )
              : plural('commerce.cart.unavailable_many', unavailable.length, {
                  other: '{count} courses are no longer for sale. Remove them to continue.',
                })}
          </Alert>
        )}

        {checkoutError && (
          <Alert
            color={checkoutError.isBillingBlocked ? 'yellow' : 'red'}
            icon={<IconAlertTriangle size={16} />}
          >
            {checkoutError.message}
          </Alert>
        )}

        <Card withBorder>
          <CouponField cart={cart} />

          {/* Both figures are the server's; the page subtracts nothing itself. */}
          {cart.estimated_discount_minor > 0 ? (
            <Stack gap={4} mt="sm">
              <Group justify="space-between">
                <Text size="sm" c="dimmed">
                  {t('commerce.summary.subtotal', 'Subtotal')}
                </Text>
                <Text size="sm">{formatMinor(cart.estimated_subtotal_minor, cart.currency)}</Text>
              </Group>
              <Group justify="space-between">
                <Text size="sm" c="dimmed">
                  {cart.coupon
                    ? t('commerce.summary.discount_code', 'Discount ({code})', {
                        code: cart.coupon.code,
                      })
                    : t('commerce.summary.discount', 'Discount')}
                </Text>
                <Text size="sm" c="green.7">
                  −{formatMinor(cart.estimated_discount_minor, cart.currency)}
                </Text>
              </Group>
            </Stack>
          ) : null}

          <Divider my="sm" />

          <Group justify="space-between" align="baseline">
            <Text c="dimmed">{t('commerce.cart.estimated_total', 'Estimated total')}</Text>
            <Title order={3}>{formatMinor(cart.estimated_total_minor, cart.currency)}</Title>
          </Group>

          <Text size="xs" c="dimmed" mt={4}>
            {t('commerce.cart.estimate_note', 'Prices are confirmed when you check out.')}
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
            {t('commerce.cart.checkout', 'Check out')}
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
            {t('commerce.cart.line_unavailable', 'No longer for sale')}
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
          aria-label={t('commerce.cart.remove_line', 'Remove {title} from your basket', {
            title: line.title,
          })}
        >
          <IconTrash size={16} />
        </Button>
      </Group>
    </Group>
  );
}
