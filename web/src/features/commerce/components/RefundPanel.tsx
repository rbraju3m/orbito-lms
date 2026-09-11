import {
  Alert,
  Badge,
  Button,
  Card,
  Checkbox,
  Group,
  Modal,
  NumberInput,
  SegmentedControl,
  Stack,
  Text,
  Textarea,
  Title,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { useDisclosure } from '@mantine/hooks';
import { IconAlertTriangle, IconReceiptRefund } from '@tabler/icons-react';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';

import { useSession } from '@/features/auth/hooks/useSession';
import { formatDateTime } from '@/shared/lib/datetime';
import { applyServerErrors } from '@/shared/lib/form';
import { formatMinor, toMajor, toMinor } from '@/shared/lib/money';
import { optionalNumberValue } from '@/shared/lib/numberValue';

import { useRefundOrder } from '../api/refunds';
import type { Order, RefundStatus } from '../api/types';
import { refundSchema, type RefundValues } from '../refundSchema';

const STATUS_COLOR: Record<RefundStatus, string> = {
  completed: 'green',
  pending: 'yellow',
  failed: 'red',
};

/**
 * What was given back on an order — for the learner as well as for staff —
 * and, for staff with `order.refund`, a way to give more back.
 */
export function RefundPanel({ order }: { order: Order }) {
  const { can } = useSession();
  const refunds = order.refunds ?? [];
  const refundable = order.refundable_minor ?? 0;
  const mayRefund = can('order.refund') && refundable > 0;

  if (refunds.length === 0 && !mayRefund) return null;

  return (
    <Card withBorder>
      <Stack gap="sm">
        <Group justify="space-between">
          <Title order={3} size="h5">
            Refunds
          </Title>
          {mayRefund ? <RefundDialog order={order} refundable={refundable} /> : null}
        </Group>

        {refunds.length === 0 ? (
          <Text size="sm" c="dimmed">
            Nothing refunded yet.
          </Text>
        ) : (
          refunds.map((refund) => (
            <Stack key={refund.id} gap={2}>
              <Group justify="space-between" wrap="nowrap">
                <Group gap="xs" wrap="nowrap">
                  <Text fw={500}>{formatMinor(refund.amount_minor, refund.currency)}</Text>
                  <Badge color={STATUS_COLOR[refund.status]} variant="light">
                    {refund.status_label}
                  </Badge>
                </Group>
                <Text size="xs" c="dimmed">
                  {formatDateTime(refund.completed_at ?? refund.created_at)}
                </Text>
              </Group>
              <Text size="xs" c="dimmed">
                {refund.method_label}
                {refund.reason ? ` · ${refund.reason}` : ''}
              </Text>
              {refund.status === 'failed' && refund.failure_reason ? (
                <Text size="xs" c="red.7">
                  {refund.failure_reason}
                </Text>
              ) : null}
            </Stack>
          ))
        )}
      </Stack>
    </Card>
  );
}

function RefundDialog({ order, refundable }: { order: Order; refundable: number }) {
  const [opened, { open, close }] = useDisclosure(false);
  const refund = useRefundOrder(order.id);
  const [formError, setFormError] = useState<string | null>(null);

  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RefundValues>({
    resolver: zodResolver(refundSchema),
    defaultValues: {
      amount: toMajor(refundable, order.currency),
      method: 'gateway',
      reason: '',
      revoke_access: true,
    },
  });

  const amount = useWatch({ control, name: 'amount' });
  const method = useWatch({ control, name: 'method' });
  // Only a refund that empties the order can take access away — which is the
  // server's rule; the form just stops offering a checkbox that would do nothing.
  const emptiesOrder = amount !== null && toMinor(amount, order.currency) === refundable;

  const submit = handleSubmit(async (values) => {
    setFormError(null);
    const minor = toMinor(values.amount ?? 0, order.currency);

    if (minor > refundable) {
      setError('amount', { message: `At most ${formatMinor(refundable, order.currency)} is left.` });
      return;
    }

    try {
      await refund.mutateAsync({
        amount_minor: minor,
        method: values.method,
        reason: values.reason.trim() || null,
        revoke_access: emptiesOrder && values.revoke_access,
      });
      close();
    } catch (error) {
      setFormError(applyServerErrors(error, setError, ['method', 'reason']));
    }
  });

  return (
    <>
      <Button variant="light" size="compact-sm" leftSection={<IconReceiptRefund size={14} />} onClick={open}>
        Refund…
      </Button>

      <Modal opened={opened} onClose={close} title={`Refund order ${order.number}`} centered>
        <form onSubmit={submit} noValidate>
          <Stack gap="md">
            {formError ? (
              <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
                {formError}
              </Alert>
            ) : null}

            <Controller
              control={control}
              name="amount"
              render={({ field, fieldState }) => (
                <NumberInput
                  label={`Amount (${order.currency})`}
                  description={`Up to ${formatMinor(refundable, order.currency)}.`}
                  min={0}
                  decimalScale={2}
                  value={field.value ?? ''}
                  onChange={(value) => field.onChange(optionalNumberValue(value))}
                  error={fieldState.error?.message}
                />
              )}
            />

            <Stack gap={4}>
              <Text size="sm" fw={500} id="refund-method-label">
                How
              </Text>
              <Controller
                control={control}
                name="method"
                render={({ field }) => (
                  <SegmentedControl
                    aria-labelledby="refund-method-label"
                    value={field.value}
                    onChange={(value) => field.onChange(value)}
                    data={[
                      { label: 'Through the provider', value: 'gateway' },
                      { label: 'Refunded elsewhere', value: 'external' },
                    ]}
                  />
                )}
              />
              <Text size="xs" c="dimmed">
                {method === 'gateway'
                  ? 'The money goes back the way it was paid.'
                  : 'Already refunded by bank transfer or in cash — this only records it. Refunds made in the provider’s dashboard arrive here by themselves; recording one as well would count it twice.'}
              </Text>
            </Stack>

            <Textarea
              label="Reason"
              description="The learner sees this on their order."
              autosize
              minRows={2}
              {...register('reason')}
              error={errors.reason?.message}
            />

            {emptiesOrder ? (
              <Controller
                control={control}
                name="revoke_access"
                render={({ field }) => (
                  <Checkbox
                    label="Take away the courses and downloads this order gave"
                    description="Untick for a goodwill refund. Access from anywhere else is never touched."
                    checked={field.value}
                    onChange={(event) => field.onChange(event.currentTarget.checked)}
                  />
                )}
              />
            ) : (
              <Text size="xs" c="dimmed">
                A partial refund leaves access as it is.
              </Text>
            )}

            <Group justify="flex-end">
              <Button variant="subtle" onClick={close}>
                Cancel
              </Button>
              <Button type="submit" color="red" loading={refund.isPending}>
                Refund {amount !== null ? formatMinor(toMinor(amount, order.currency), order.currency) : ''}
              </Button>
            </Group>
          </Stack>
        </form>
      </Modal>
    </>
  );
}
