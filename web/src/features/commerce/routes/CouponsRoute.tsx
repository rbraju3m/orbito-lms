import {
  Alert,
  Badge,
  Button,
  Card,
  Code,
  Container,
  Group,
  Modal,
  Pagination,
  Stack,
  Text,
} from '@mantine/core';
import { IconAlertTriangle, IconPlus, IconTicket } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { couponsQuery, useDeleteCoupon, type Coupon, type CouponState } from '../api/coupons';
import { CouponForm } from '../components/CouponForm';
import { describeDiscount, describeScope, describeUsage } from '../lib/coupons';

const STATE_COLOR: Record<CouponState, string> = {
  active: 'green',
  scheduled: 'blue',
  expired: 'gray',
  used_up: 'orange',
  off: 'gray',
};

/**
 * An academy's coupons (`coupon.manage`). The state badge is the server's —
 * derived from the switch, the dates and PAID uses — so the page never
 * decides for itself whether a coupon is live.
 */
export function CouponsRoute() {
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(couponsQuery(page));
  const [editing, setEditing] = useState<Coupon | 'new' | null>(null);
  const [deleting, setDeleting] = useState<Coupon | null>(null);
  const remove = useDeleteCoupon();

  if (isPending) return <LoadingState rows={3} label="Loading coupons" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const deleteError = remove.error instanceof ApiError ? remove.error.message : null;

  const closeDelete = () => {
    setDeleting(null);
    remove.reset();
  };

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Coupons"
        description="Codes your learners type at checkout for money off."
        actions={
          data.data.length > 0 ? (
            <Button leftSection={<IconPlus size={16} />} onClick={() => setEditing('new')}>
              New coupon
            </Button>
          ) : undefined
        }
      />

      {data.data.length === 0 ? (
        <EmptyState
          icon={IconTicket}
          title="No coupons yet"
          description="Create a code for a launch, a partner or a returning student. You choose what it takes off and how often it can be used."
          action={{ label: 'New coupon', onClick: () => setEditing('new') }}
        />
      ) : (
        <Stack gap="sm">
          {data.data.map((coupon) => (
            <Card key={coupon.id} withBorder>
              <Group justify="space-between" gap="md" wrap="wrap">
                <Stack gap={4} style={{ minWidth: 0 }}>
                  <Group gap="xs">
                    <Code fz="sm" fw={600}>
                      {coupon.code}
                    </Code>
                    <Badge color={STATE_COLOR[coupon.state]} variant="light">
                      {coupon.state_label}
                    </Badge>
                  </Group>
                  <Text size="sm">
                    {describeDiscount(coupon)} · {describeScope(coupon)}
                  </Text>
                  <Text size="xs" c="dimmed">
                    {describeUsage(coupon)}
                    {coupon.ends_at ? ` · until ${formatDateTime(coupon.ends_at)}` : ''}
                    {coupon.description ? ` · ${coupon.description}` : ''}
                  </Text>
                </Stack>

                <Group gap="xs" wrap="nowrap">
                  <Button variant="light" size="compact-sm" onClick={() => setEditing(coupon)}>
                    Edit
                  </Button>
                  <Button
                    variant="subtle"
                    color="red"
                    size="compact-sm"
                    aria-label={`Delete ${coupon.code}`}
                    onClick={() => setDeleting(coupon)}
                  >
                    Delete
                  </Button>
                </Group>
              </Group>
            </Card>
          ))}

          {data.meta.last_page > 1 ? (
            <Group justify="center">
              <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
            </Group>
          ) : null}
        </Stack>
      )}

      <Modal
        opened={editing !== null}
        onClose={() => setEditing(null)}
        title={editing === 'new' ? 'New coupon' : `Edit ${editing?.code ?? ''}`}
        size="lg"
      >
        {editing !== null ? (
          <CouponForm
            key={editing === 'new' ? 'new' : editing.id}
            coupon={editing === 'new' ? null : editing}
            onDone={() => setEditing(null)}
          />
        ) : null}
      </Modal>

      <Modal opened={deleting !== null} onClose={closeDelete} title={`Delete ${deleting?.code ?? ''}?`} centered>
        <Stack gap="sm">
          {deleteError ? (
            <Alert color="yellow" icon={<IconAlertTriangle size={16} />} role="alert">
              {deleteError}
            </Alert>
          ) : (
            <Text size="sm">
              Only a coupon nobody has used can be deleted. One on an order is part of that order's
              record — switch it off instead.
            </Text>
          )}
          <Group justify="flex-end">
            <Button variant="subtle" onClick={closeDelete}>
              Keep it
            </Button>
            <Button
              color="red"
              loading={remove.isPending}
              disabled={deleteError !== null}
              onClick={() => deleting && remove.mutate(deleting.id, { onSuccess: closeDelete })}
            >
              Delete
            </Button>
          </Group>
        </Stack>
      </Modal>
    </Container>
  );
}
