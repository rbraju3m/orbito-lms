import { Alert, Button, Group, Stack, Text } from '@mantine/core';
import { IconAlertTriangle, IconCheck, IconShoppingCartPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { Link, useNavigate } from 'react-router';

import type { CoursePrice } from '@/features/catalog/api/types';
import { ApiError } from '@/shared/api/errors';

import { cartQuery, useAddToCart } from '../api/queries';
import { PriceTag } from './PriceTag';

/**
 * Buying a paid course.
 *
 * Two states the page must not confuse: a paid course with no `price` is NOT
 * free — it is temporarily unbuyable, because its product was deactivated or
 * it is not sold in this academy's currency. Saying "free" there would be a
 * lie the checkout would then refuse.
 */
export function BuyPanel({
  price,
  courseTitle,
  disabled = false,
  disabledReason,
}: {
  price: CoursePrice | null;
  courseTitle: string;
  disabled?: boolean;
  disabledReason?: string | undefined;
}) {
  const navigate = useNavigate();
  const cart = useQuery(cartQuery());
  const addToCart = useAddToCart();

  if (price === null) {
    return (
      <Stack gap="sm">
        <Text fw={700} size="xl">
          Not available
        </Text>
        <Text size="sm" c="dimmed">
          This course is not on sale at the moment. It may be back shortly.
        </Text>
      </Stack>
    );
  }

  // Reading the cache rather than tracking a local "added" flag: the basket is
  // server state, and a component that remembers its own copy will disagree
  // with it the moment another tab adds something (CLAUDE.md §5).
  const inBasket = cart.data?.items.some((line) => line.product_id === price.product_id) ?? false;

  const error = addToCart.error instanceof ApiError ? addToCart.error : null;

  return (
    <Stack gap="sm">
      <PriceTag
        amountMinor={price.amount_minor}
        currency={price.currency}
        listAmountMinor={price.list_amount_minor}
      />

      {error && (
        <Alert
          color={error.isBillingBlocked ? 'yellow' : 'red'}
          icon={<IconAlertTriangle size={16} />}
        >
          {error.message}
        </Alert>
      )}

      {inBasket ? (
        <Stack gap={6}>
          <Group gap={6} c="green">
            <IconCheck size={16} />
            <Text size="sm" fw={500}>
              In your basket
            </Text>
          </Group>
          <Button component={Link} to="/cart" variant="light" fullWidth>
            Go to basket
          </Button>
        </Stack>
      ) : (
        <Button
          fullWidth
          disabled={disabled}
          loading={addToCart.isPending}
          leftSection={<IconShoppingCartPlus size={16} />}
          onClick={() =>
            addToCart.mutate(price.product_id, {
              onSuccess: () => void navigate('/cart'),
            })
          }
        >
          Buy this course
        </Button>
      )}

      {/* The reason lives under the button, not in a tooltip nobody opens. */}
      {disabled && disabledReason && (
        <Text size="xs" c="dimmed" ta="center">
          {disabledReason}
        </Text>
      )}

      <Text size="xs" c="dimmed" ta="center">
        You will be able to review {courseTitle} before paying.
      </Text>
    </Stack>
  );
}
