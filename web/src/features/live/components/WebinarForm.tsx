import {
  Alert,
  Button,
  Group,
  NumberInput,
  Select,
  Stack,
  Switch,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useState } from 'react';
import { Controller, useForm, useWatch } from 'react-hook-form';

import { toLocalInputValue } from '@/shared/lib/datetime';
import { applyServerErrors } from '@/shared/lib/form';

import { useSession } from '@/features/auth/hooks/useSession';
import { formatMinor } from '@/shared/lib/money';

import { useSaveWebinar, useSetWebinarPrice } from '../api/queries';
import type { LiveProviderOption, Webinar } from '../api/types';
import { toWebinarInput, webinarSchema, type WebinarValues } from '../webinarSchema';

const FIELDS = [
  'title',
  'description',
  'capacity',
  'is_paid',
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
      is_paid: existing?.is_paid ?? false,
      provider: firstAvailable,
      join_url: '',
      starts_at: toLocalInputValue(existing?.session?.starts_at),
      ends_at: toLocalInputValue(existing?.session?.ends_at),
    },
  });

  const provider = useWatch({ control, name: 'provider' });
  const isPaid = useWatch({ control, name: 'is_paid' });

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

        <Controller
          control={control}
          name="is_paid"
          render={({ field }) => (
            <Switch
              label="People pay for a place"
              description="Free events are open to everybody in the academy."
              checked={field.value}
              onChange={(event) => field.onChange(event.currentTarget.checked)}
            />
          )}
        />

        {/*
         * The price is its own write, and it is offered only once the SERVER
         * says the webinar is paid — flipping the switch above and typing a
         * figure before saving would post a price at a product that does not
         * exist yet, and be refused.
         */}
        {isPaid && existing?.is_paid === true ? (
          <WebinarPrice webinar={existing} />
        ) : isPaid ? (
          <Text size="xs" c="dimmed">
            Save first, then set the price. A paid webinar cannot be published until it has one.
          </Text>
        ) : null}

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

/**
 * What a place costs.
 *
 * A separate write from the rest of the form, because a price hangs off a
 * product — the same split the course and download editors make, and the same
 * minor-units box, so an academy meets one convention rather than three.
 */
function WebinarPrice({ webinar }: { webinar: Webinar }) {
  const { session } = useSession();
  const currency = session?.academy?.currency ?? 'USD';
  const setPrice = useSetWebinarPrice(webinar.id);

  // Mantine reports a string for anything not yet canonical ("070", "1."), so
  // this is the union and never assumed to be a number (§ Phase 7).
  const [amount, setAmount] = useState<string | number>(webinar.price?.amount_minor ?? '');

  return (
    <Stack gap={4}>
      <Text size="xs" c="dimmed">
        In {currency}, in minor units — {formatMinor(100, currency)} is entered as 100.
      </Text>
      <Group align="flex-end" gap="sm">
        <NumberInput
          label={`Price (${currency})`}
          min={1}
          value={amount}
          onChange={setAmount}
          flex={1}
        />
        <Button
          variant="light"
          loading={setPrice.isPending}
          disabled={typeof amount !== 'number'}
          onClick={() =>
            typeof amount === 'number' && setPrice.mutate({ currency, amount_minor: amount })
          }
        >
          Set price
        </Button>
      </Group>
    </Stack>
  );
}
