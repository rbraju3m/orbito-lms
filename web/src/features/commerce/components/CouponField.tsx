import { Alert, Badge, Button, Group, Stack, Text, TextInput } from '@mantine/core';
import { IconAlertTriangle, IconTicket } from '@tabler/icons-react';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';

import { useApplyCoupon, useRemoveCoupon } from '../api/coupons';
import type { Cart } from '../api/types';

/**
 * Entering a code, or the code already on the basket.
 *
 * Whether it applies — and why not — is the SERVER's answer (`cart.coupon`),
 * computed by the same rules checkout enforces. This component decides
 * nothing: an applied coupon that has stopped applying shows the server's
 * reason, and the checkout button is already disabled by `is_checkoutable`.
 */
export function CouponField({ cart }: { cart: Cart }) {
  const apply = useApplyCoupon();
  const remove = useRemoveCoupon();
  const [code, setCode] = useState('');

  const applyError = apply.error instanceof ApiError ? apply.error.message : null;

  if (cart.coupon) {
    return (
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap">
          <Group gap="xs" wrap="nowrap" style={{ minWidth: 0 }}>
            <IconTicket size={16} />
            <Badge variant="light" color={cart.coupon.applies ? 'green' : 'gray'}>
              {cart.coupon.code}
            </Badge>
            {cart.coupon.description ? (
              <Text size="sm" c="dimmed" truncate>
                {cart.coupon.description}
              </Text>
            ) : null}
          </Group>
          <Button
            variant="subtle"
            color="gray"
            size="compact-sm"
            loading={remove.isPending}
            onClick={() => remove.mutate()}
          >
            Remove code
          </Button>
        </Group>

        {!cart.coupon.applies && cart.coupon.message ? (
          <Alert color="yellow" icon={<IconAlertTriangle size={16} />} role="alert">
            {cart.coupon.message} Remove it to check out at the full price.
          </Alert>
        ) : null}
      </Stack>
    );
  }

  return (
    <form
      onSubmit={(event) => {
        event.preventDefault();
        if (code.trim() === '') return;
        apply.mutate(code.trim(), { onSuccess: () => setCode('') });
      }}
    >
      <Group align="flex-start" gap="xs" wrap="nowrap">
        <TextInput
          label="Coupon code"
          placeholder="LAUNCH20"
          value={code}
          onChange={(event) => setCode(event.currentTarget.value)}
          error={applyError}
          autoCapitalize="characters"
          autoComplete="off"
          style={{ flex: 1 }}
        />
        <Button type="submit" variant="light" mt={24} loading={apply.isPending} disabled={code.trim() === ''}>
          Apply
        </Button>
      </Group>
    </form>
  );
}
