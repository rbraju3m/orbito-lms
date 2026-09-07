import { Alert, Button, Group, Modal, Stack, Text, TextInput } from '@mantine/core';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { fromLocalInputValue, toLocalInputValue } from '@/shared/lib/datetime';

import type { CourseStudent, EnrollmentAction } from '../api/types';

interface Props {
  student: CourseStudent | null;
  action: EnrollmentAction | null;
  pending: boolean;
  error: unknown;
  onConfirm: (input: { reason?: string; expires_at?: string | null }) => void;
  onClose: () => void;
}

const COPY: Record<EnrollmentAction, { title: string; body: string; confirm: string }> = {
  suspend: {
    title: 'Suspend access',
    body: 'They keep their place and their progress. Access closes until you reinstate it.',
    confirm: 'Suspend',
  },
  reinstate: {
    title: 'Reinstate access',
    body: 'Access reopens where they left off. A learner who had finished returns to completed.',
    confirm: 'Reinstate',
  },
  extend: {
    title: 'Change access period',
    body: 'Clear the date for indefinite access. Moving it into the future also un-expires them.',
    confirm: 'Save',
  },
  revoke: {
    title: 'Revoke access',
    body: 'Their enrolment is cancelled. The progress, quiz attempts and graded work are all kept, so re-enrolling returns them to it.',
    confirm: 'Revoke access',
  },
};

/**
 * One modal for all four transitions. Each is a distinct server-side Action,
 * but they ask the user for at most a reason or a date, and four near-identical
 * modals would drift apart.
 */
export function EnrollmentActionModal({
  student,
  action,
  pending,
  error,
  onConfirm,
  onClose,
}: Props) {
  const [reason, setReason] = useState('');
  const [expiresAt, setExpiresAt] = useState('');

  const open = student !== null && action !== null;
  const copy = action ? COPY[action] : null;

  /*
   * Re-seed the fields whenever a different row or action opens the modal.
   * Adjusting state during render is the documented React pattern for this —
   * an effect would paint the previous row's dates for one frame.
   */
  const [seededFor, setSeededFor] = useState<string | null>(null);

  if (student !== null && action !== null) {
    const seedKey = `${student.enrollment.id}:${action}`;

    if (seedKey !== seededFor) {
      setSeededFor(seedKey);
      setReason('');
      setExpiresAt(toLocalInputValue(student.enrollment.expires_at));
    }
  }

  const apiError = error instanceof ApiError ? error : null;

  return (
    <Modal opened={open} onClose={onClose} title={copy?.title ?? ''} centered>
      {copy && student && (
        <Stack gap="md">
          <Text size="sm">{copy.body}</Text>

          <Text size="sm" c="dimmed">
            {student.student.name ?? student.student.email}
          </Text>

          {action === 'extend' && (
            <TextInput
              type="datetime-local"
              label="Access ends"
              description="Leave empty for indefinite access."
              value={expiresAt}
              onChange={(event) => setExpiresAt(event.currentTarget.value)}
            />
          )}

          {(action === 'suspend' || action === 'revoke') && (
            <TextInput
              label="Reason"
              description="Shown to staff on the roster, not to the learner."
              placeholder="Optional"
              value={reason}
              onChange={(event) => setReason(event.currentTarget.value)}
            />
          )}

          {apiError && (
            <Alert
              color={apiError.isSubscriptionLapsed ? 'yellow' : 'red'}
              icon={<IconAlertTriangle size={16} />}
            >
              {apiError.message}
            </Alert>
          )}

          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button
              color={action === 'revoke' ? 'red' : undefined}
              loading={pending}
              onClick={() =>
                onConfirm(
                  action === 'extend'
                    ? { expires_at: fromLocalInputValue(expiresAt) }
                    : { reason: reason.trim() || undefined },
                )
              }
            >
              {copy.confirm}
            </Button>
          </Group>
        </Stack>
      )}
    </Modal>
  );
}
