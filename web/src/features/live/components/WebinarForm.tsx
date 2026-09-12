import { Alert, Button, Group, NumberInput, Select, Stack, Text, Textarea, TextInput } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';

import { toLocalInputValue } from '@/shared/lib/datetime';
import { applyServerErrors } from '@/shared/lib/form';

import { useSaveWebinar } from '../api/queries';
import type { LiveProviderOption, Webinar } from '../api/types';
import { toWebinarInput, webinarSchema, type WebinarValues } from '../webinarSchema';

const FIELDS = [
  'title',
  'description',
  'capacity',
  'provider',
  'join_url',
  'starts_at',
  'ends_at',
] as const;

/**
 * Scheduling a webinar, or editing one.
 *
 * A new webinar carries its SESSION — it would have nothing to attend and
 * could not be published otherwise — and an edit does not: the time moves
 * through the session, which is where the reminder is reset and the provider
 * told. That is why half this form disappears on an edit rather than being
 * shown disabled: there is nothing misleading about a field that is not
 * there, and plenty about one that looks editable.
 */
export function WebinarForm({
  existing,
  providers,
  timezone,
  onDone,
}: {
  existing: Webinar | null;
  providers: LiveProviderOption[];
  timezone: string;
  onDone: () => void;
}) {
  const isNew = existing === null;
  const save = useSaveWebinar();
  const [failure, setFailure] = useState<string | null>(null);

  const firstAvailable = providers.find((option) => option.available)?.value ?? 'manual';

  const {
    control,
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<WebinarValues>({
    resolver: zodResolver(webinarSchema(isNew)),
    defaultValues: {
      title: existing?.title ?? '',
      description: existing?.description ?? '',
      capacity: existing?.capacity === null || existing === null ? '' : String(existing.capacity),
      provider: firstAvailable,
      join_url: '',
      starts_at: toLocalInputValue(existing?.session?.starts_at),
      ends_at: toLocalInputValue(existing?.session?.ends_at),
    },
  });

  const provider = useWatch({ control, name: 'provider' });

  const submit = handleSubmit((values) => {
    setFailure(null);

    save.mutate(
      { id: existing?.id, ...toWebinarInput(values, { isNew, timezone }) },
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
          description="Optional. Shown with the webinar in the list."
          autosize
          minRows={2}
          {...register('description')}
          error={errors.description?.message}
        />

        <Controller
          control={control}
          name="capacity"
          render={({ field }) => (
            <NumberInput
              label="Places"
              description="Leave empty for no limit. Uncapped is not the same as full."
              min={1}
              /*
               * A string for anything not yet canonical ("07", "1."), so the
               * form holds the string and the schema does the converting
               * (§ Patterns established in Phase 7).
               */
              value={field.value}
              onChange={(value) => field.onChange(String(value ?? ''))}
              error={errors.capacity?.message}
            />
          )}
        />

        {isNew ? (
          <>
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
                  error={errors.provider?.message}
                />
              )}
            />

            {provider === 'manual' && (
              <TextInput
                label="Join link"
                type="url"
                placeholder="https://…"
                required
                description="Registrants get it as the webinar is about to start, and following it is what records their attendance."
                {...register('join_url')}
                error={errors.join_url?.message}
              />
            )}

            <Group grow>
              <TextInput
                label="Starts"
                type="datetime-local"
                required
                {...register('starts_at')}
                error={errors.starts_at?.message}
              />
              <TextInput
                label="Ends"
                type="datetime-local"
                required
                {...register('ends_at')}
                error={errors.ends_at?.message}
              />
            </Group>

            <Text size="xs" c="dimmed">
              Times are in your time zone ({timezone}), and that is the zone the webinar records.
            </Text>
          </>
        ) : (
          <Text size="xs" c="dimmed">
            The date is part of the session. Cancel this webinar and schedule another to move it.
          </Text>
        )}

        {failure && (
          <Alert color="red" icon={<IconAlertTriangle size={16} />}>
            {failure}
          </Alert>
        )}

        <Group justify="flex-end">
          <Button variant="subtle" onClick={onDone}>
            Cancel
          </Button>
          <Button type="submit" loading={save.isPending}>
            {isNew ? 'Schedule it' : 'Save'}
          </Button>
        </Group>
      </Stack>
    </form>
  );
}
