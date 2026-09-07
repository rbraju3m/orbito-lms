import { Alert, Badge, Button, Card, Group, Stack, Text, Textarea, Title } from '@mantine/core';
import { IconAlertCircle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { LoadingState } from '@/shared/ui';

import { instructorApplicationQuery, useApplyAsInstructor } from '../api/queries';

const STATUS_COLOR: Record<string, string> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
  blocked: 'danger',
};

export function InstructorApplicationCard() {
  const { data, isPending } = useQuery(instructorApplicationQuery());
  const { mutateAsync, isPending: isApplying } = useApplyAsInstructor();
  const [message, setMessage] = useState('');
  const [error, setError] = useState<string | null>(null);

  if (isPending) return <LoadingState rows={1} height={120} />;

  // `undefined` (query not resolved) and `null` (never applied) both mean
  // "no application on file"; only a real profile blocks a new one.
  const profile = data ?? null;
  const canApply = profile === null || profile.status === 'rejected';

  return (
    <Card>
      <Stack gap="md">
        <Group justify="space-between">
          <Title order={3}>Teaching</Title>
          {profile ? (
            <Badge color={STATUS_COLOR[profile.status] ?? 'gray'} variant="light">
              {profile.status_label}
            </Badge>
          ) : null}
        </Group>

        {error ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {error}
          </Alert>
        ) : null}

        {profile?.status === 'pending' ? (
          <Text size="sm" c="dimmed">
            Your application is with our team. We'll email you when it's reviewed.
          </Text>
        ) : null}

        {profile?.status === 'approved' ? (
          <Text size="sm" c="dimmed">
            You're an approved instructor. Head to Studio to build a course.
          </Text>
        ) : null}

        {profile?.status === 'blocked' ? (
          <Text size="sm" c="dimmed">
            Your instructor account is blocked. Contact support if you think this is a mistake.
          </Text>
        ) : null}

        {profile?.review_note ? (
          <Alert color="gray" variant="light" title="Reviewer note">
            {profile.review_note}
          </Alert>
        ) : null}

        {canApply ? (
          <Stack gap="sm">
            <Text size="sm" c="dimmed">
              {profile === null
                ? 'Apply to teach on Orbito. An administrator reviews every application.'
                : 'You can revise your application and submit it again.'}
            </Text>

            <Textarea
              label="Tell us what you'd like to teach"
              autosize
              minRows={3}
              value={message}
              onChange={(event) => setMessage(event.currentTarget.value)}
            />

            <Group justify="flex-end">
              <Button
                loading={isApplying}
                onClick={() => {
                  setError(null);
                  void mutateAsync(message || undefined).catch((err: unknown) => {
                    setError(err instanceof ApiError ? err.message : 'Something went wrong.');
                  });
                }}
              >
                Apply to teach
              </Button>
            </Group>
          </Stack>
        ) : null}
      </Stack>
    </Card>
  );
}
