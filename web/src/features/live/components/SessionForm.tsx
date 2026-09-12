import {
  Alert,
  Anchor,
  Button,
  Group,
  Select,
  Stack,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';
import { Link } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { toLocalInputValue } from '@/shared/lib/datetime';
import { applyServerErrors } from '@/shared/lib/form';

import { useSaveSession } from '../api/queries';
import type { Cohort, LiveProviderOption, LiveSession } from '../api/types';
import { EVERYONE, sessionSchema, toSessionInput, type SessionValues } from '../sessionSchema';

const FIELDS = [
  'title',
  'description',
  'provider',
  'join_url',
  'starts_at',
  'ends_at',
  'cohort_id',
] as const;

/**
 * Scheduling a session, or moving one.
 *
 * The provider list is the server's, marked with what this academy can use
 * right now — a provider it has not connected is shown and disabled rather
 * than offered and refused on submit. Provider and run are fixed once a
 * session exists: a reschedule changes the time, the words and a pasted link,
 * and nothing else.
 */
export function SessionForm({
  courseId,
  existing,
  providers,
  cohorts,
  timezone,
  onDone,
}: {
  courseId: string;
  existing: LiveSession | null;
  providers: LiveProviderOption[];
  cohorts: Cohort[];
  timezone: string;
  onDone: () => void;
}) {
  const isNew = existing === null;
  const save = useSaveSession(courseId);
  const [failure, setFailure] = useState<string | null>(null);

  const firstAvailable = providers.find((option) => option.available)?.value ?? 'manual';

  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<SessionValues>({
    resolver: zodResolver(sessionSchema(isNew)),
    defaultValues: {
      title: existing?.title ?? '',
      description: existing?.description ?? '',
      provider: existing?.provider ?? firstAvailable,
      join_url: '',
      starts_at: toLocalInputValue(existing?.starts_at),
      ends_at: toLocalInputValue(existing?.ends_at),
      cohort_id: existing?.cohort?.id ?? EVERYONE,
    },
  });

  const provider = useWatch({ control, name: 'provider' });
  /*
   * "Not connected" is a dead end for whoever can do something about it, and
   * noise for whoever cannot — so the way out is shown only to the admin who
   * holds the key. Same instinct as a 423 that names how to get in.
   */
  const { canAny } = useSession();
  const canConnect = canAny(['live.provider.manage']);
  const unconnected = providers.some((option) => !option.available);
  const providerLabel = providers.find((option) => option.value === provider)?.label ?? provider;

  // A new session is offered only runs that can still happen; an edit shows
  // whichever run it already belongs to.
  const runs = cohorts.filter(
    (cohort) =>
      !isNew ||
      (cohort.status !== 'cancelled' && cohort.status !== 'completed'),
  );

  const submit = handleSubmit((values) => {
    setFailure(null);
    save.mutate(
      { ...(existing ? { id: existing.id } : {}), ...toSessionInput(values, { isNew, timezone }) },
      {
        onSuccess: onDone,
        onError: (error) => setFailure(applyServerErrors(error, setError, FIELDS)),
      },
    );
  });

  return (
    <form onSubmit={(event) => void submit(event)} noValidate>
      <Stack gap="sm">
        <TextInput label="Title" required {...register('title')} error={errors.title?.message} />

        <Textarea
          label="Description"
          description="Optional. Learners see it with the session."
          autosize
          minRows={2}
          {...register('description')}
          error={errors.description?.message}
        />

        <Controller
          control={control}
          name="provider"
          render={({ field }) => (
            <Select
              label="Where it happens"
              data={providers.map((option) => ({
                value: option.value,
                label: option.available ? option.label : `${option.label} — not connected`,
                disabled: !option.available,
              }))}
              value={field.value}
              onChange={(value) => {
                if (value) field.onChange(value);
              }}
              allowDeselect={false}
              disabled={!isNew}
              description={
                isNew ? undefined : 'Fixed once scheduled. Cancel it and schedule again to move it.'
              }
              error={errors.provider?.message}
            />
          )}
        />

        {isNew && unconnected && canConnect && (
          <Text size="xs" c="dimmed">
            A provider shown as not connected needs the academy's account.{' '}
            <Anchor component={Link} to="/admin/live-providers" size="xs">
              Connect one
            </Anchor>
            .
          </Text>
        )}

        {provider === 'manual' ? (
          <TextInput
            label="Join link"
            type="url"
            placeholder="https://…"
            required={isNew}
            description={
              isNew
                ? 'Any meeting link. Learners get it only as the session is about to start, and following it is what records their attendance.'
                : 'Leave empty to keep the link already saved.'
            }
            {...register('join_url')}
            error={errors.join_url?.message}
          />
        ) : (
          <Text size="xs" c="dimmed">
            {providerLabel} creates the meeting and its link when you save.
          </Text>
        )}

        <Group grow align="flex-start">
          <TextInput
            type="datetime-local"
            label="Starts"
            required
            {...register('starts_at')}
            error={errors.starts_at?.message}
          />
          <TextInput
            type="datetime-local"
            label="Ends"
            required
            {...register('ends_at')}
            error={errors.ends_at?.message}
          />
        </Group>
        <Text size="xs" c="dimmed">
          {isNew
            ? `In your time zone (${timezone}), which the session records.`
            : `In your time zone. The session keeps the zone it was scheduled in (${existing.timezone}).`}
        </Text>

        <Controller
          control={control}
          name="cohort_id"
          render={({ field }) => (
            <Select
              label="Who it is for"
              data={[
                { value: EVERYONE, label: 'Everybody on the course' },
                ...runs.map((cohort) => ({ value: cohort.id, label: cohort.name })),
              ]}
              value={field.value}
              onChange={(value) => field.onChange(value ?? EVERYONE)}
              allowDeselect={false}
              disabled={!isNew}
              description={
                isNew
                  ? "A session for one run is only for that run's learners."
                  : 'Fixed once scheduled.'
              }
            />
          )}
        />

        {failure ? (
          <Alert color="red" icon={<IconAlertTriangle size={16} />}>
            {failure}
          </Alert>
        ) : null}

        <Group justify="flex-end" gap="xs">
          <Button variant="subtle" onClick={onDone}>
            Close
          </Button>
          <Button type="submit" loading={save.isPending}>
            {isNew ? 'Schedule' : 'Save changes'}
          </Button>
        </Group>
      </Stack>
    </form>
  );
}
