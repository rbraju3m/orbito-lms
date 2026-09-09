import { Alert, Button, Group, Modal, Stack, Text, Textarea } from '@mantine/core';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';

import type { Tenant, TenantAction } from '../api/types';

interface Props {
  tenant: Tenant | null;
  action: TenantAction | null;
  pending: boolean;
  error: unknown;
  onConfirm: (input: { reason?: string | undefined }) => void;
  onClose: () => void;
}

interface Copy {
  title: string;
  body: string;
  confirm: string;
  danger?: boolean;
  /** Only two of the four transitions have anything to record. */
  reason?: { label: string; description: string };
}

const COPY: Record<TenantAction, Copy> = {
  approve: {
    title: 'Approve this academy',
    body: 'Its schema already exists. Approving is what opens it to its owner and members — until then everyone in it is refused.',
    confirm: 'Approve and open',
  },
  reject: {
    title: 'Reject this academy',
    body: 'A final decision on a signup. The schema stays, so nothing is destroyed, but a rejected academy has no further transitions.',
    confirm: 'Reject',
    danger: true,
    reason: {
      label: 'Reason',
      description: 'Recorded on the academy. Write it for whoever reads this in six months.',
    },
  },
  suspend: {
    title: 'Suspend this academy',
    body: 'Access closes for everyone in it. The database, the courses and the learners’ progress are all kept, so reinstating is a status change and not a restore.',
    confirm: 'Suspend',
    danger: true,
    reason: {
      label: 'Reason',
      description: 'Recorded on the academy. Not shown to its members.',
    },
  },
  reactivate: {
    title: 'Reinstate this academy',
    body: 'Access reopens immediately, exactly where it closed. The suspension reason is cleared.',
    confirm: 'Reinstate',
  },
};

/**
 * One modal for all four transitions. Each is a distinct Action on the server,
 * but they ask an operator for at most a sentence, and four near-identical
 * modals would drift apart. Same shape as `EnrollmentActionModal`.
 *
 * Which transitions are OFFERED is not decided here — the caller renders
 * `tenant.available_actions`, which the server computes from the same rule it
 * enforces.
 */
export function TenantTransitionModal({
  tenant,
  action,
  pending,
  error,
  onConfirm,
  onClose,
}: Props) {
  const [reason, setReason] = useState('');

  const open = tenant !== null && action !== null;
  const copy = action ? COPY[action] : null;

  /*
   * Re-seed when a different academy or action opens the modal. Adjusting
   * state during render is the documented React pattern for this; an effect
   * would paint the previous academy's reason for one frame.
   */
  const [seededFor, setSeededFor] = useState<string | null>(null);

  if (tenant !== null && action !== null) {
    const seedKey = `${tenant.id}:${action}`;

    if (seedKey !== seededFor) {
      setSeededFor(seedKey);
      setReason('');
    }
  }

  const apiError = error instanceof ApiError ? error : null;

  return (
    <Modal opened={open} onClose={onClose} title={copy?.title ?? ''} centered>
      {copy && tenant && (
        <Stack gap="md">
          <Text size="sm">{copy.body}</Text>

          <Text size="sm" c="dimmed">
            {tenant.name} · {tenant.slug}
          </Text>

          {copy.reason && (
            <Textarea
              label={copy.reason.label}
              description={copy.reason.description}
              placeholder="Optional"
              value={reason}
              onChange={(event) => setReason(event.currentTarget.value)}
              maxLength={500}
              autosize
              minRows={2}
            />
          )}

          {apiError && (
            <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
              {apiError.message}
            </Alert>
          )}

          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button
              color={copy.danger ? 'danger' : undefined}
              loading={pending}
              onClick={() => onConfirm({ reason: reason.trim() || undefined })}
            >
              {copy.confirm}
            </Button>
          </Group>
        </Stack>
      )}
    </Modal>
  );
}
