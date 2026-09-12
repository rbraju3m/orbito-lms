import { Alert, Button, Group, NumberInput, Select, Stack, Text, TextInput } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';

import { toLocalInputValue } from '@/shared/lib/datetime';
import { applyServerErrors } from '@/shared/lib/form';
import { optionalNumberValue } from '@/shared/lib/numberValue';

import { useSaveCohort } from '../api/queries';
import type { Cohort, CohortStatus } from '../api/types';
import { cohortSchema, toCohortInput, type CohortValues } from '../cohortSchema';

const FIELDS = [
  'name',
  'starts_at',
  'ends_at',
  'enrollment_deadline',
  'capacity',
  'status',
] as const;

const STATUSES: { value: CohortStatus; label: string }[] = [
  { value: 'draft', label: 'Draft — only the course team sees it' },
  { value: 'open', label: 'Open' },
  { value: 'running', label: 'Running' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
];

/**
 * A run of the course: a start date, a capacity and a group. Whether a
 * learner can join it now — status, deadline and places together — is the
 * server's answer (`is_joinable`), shown on the list rather than decided here.
 */
export function CohortForm({
  courseId,
  existing,
  timezone,
  onDone,
}: {
  courseId: string;
  existing: Cohort | null;
  timezone: string;
  onDone: () => void;
}) {
  const isNew = existing === null;
  const save = useSaveCohort(courseId);
  const [failure, setFailure] = useState<string | null>(null);

  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<CohortValues>({
    resolver: zodResolver(cohortSchema),
    defaultValues: {
      name: existing?.name ?? '',
      starts_at: toLocalInputValue(existing?.starts_at),
      ends_at: toLocalInputValue(existing?.ends_at),
      enrollment_deadline: toLocalInputValue(existing?.enrollment_deadline),
      capacity: existing?.capacity ?? null,
      status: existing?.status ?? 'draft',
    },
  });

  const submit = handleSubmit((values) => {
    setFailure(null);
    save.mutate(
      { ...(existing ? { id: existing.id } : {}), ...toCohortInput(values, { isNew, timezone }) },
      {
        onSuccess: onDone,
        onError: (error) => setFailure(applyServerErrors(error, setError, FIELDS)),
      },
    );
  });

  return (
    <form onSubmit={(event) => void submit(event)} noValidate>
      <Stack gap="sm">
        <TextInput
          label="Name"
          placeholder="Autumn 2026"
          required
          {...register('name')}
          error={errors.name?.message}
        />

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
            description="Optional."
            {...register('ends_at')}
            error={errors.ends_at?.message}
          />
        </Group>

        <Group grow align="flex-start">
          <Controller
            control={control}
            name="capacity"
            render={({ field }) => (
              <NumberInput
                label="Places"
                description="Empty means no limit."
                min={1}
                allowDecimal={false}
                value={field.value ?? ''}
                onChange={(value) => field.onChange(optionalNumberValue(value))}
                error={errors.capacity?.message}
              />
            )}
          />
          <TextInput
            type="datetime-local"
            label="Joins close"
            description="Optional."
            {...register('enrollment_deadline')}
            error={errors.enrollment_deadline?.message}
          />
        </Group>

        <Controller
          control={control}
          name="status"
          render={({ field }) => (
            <Select
              label="Status"
              data={STATUSES}
              value={field.value}
              onChange={(value) => {
                if (value) field.onChange(value);
              }}
              allowDeselect={false}
              description="Cancelling keeps the run's sessions and learners. Only a run nobody has joined, with no sessions, can be deleted."
              error={errors.status?.message}
            />
          )}
        />

        <Text size="xs" c="dimmed">
          In your time zone{isNew ? ` (${timezone}), which the run records` : ''}.
        </Text>

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
            {isNew ? 'Create run' : 'Save changes'}
          </Button>
        </Group>
      </Stack>
    </form>
  );
}
