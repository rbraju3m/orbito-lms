import { Alert, Button, Group, Stack, TextInput } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useState } from 'react';
import { Controller, useForm } from 'react-hook-form';

import { applyServerErrors } from '@/shared/lib/form';

import type { WebhookTopicOption } from '../api/types';
import { endpointSchema, type EndpointValues } from '../schemas';
import { TopicPicker } from './TopicPicker';

const FIELDS = ['url', 'description', 'events'] as const;

export interface EndpointFormProps {
  topics: WebhookTopicOption[];
  defaultValues: EndpointValues;
  submitLabel: string;
  pending: boolean;
  /** Rejects with the API error; the form maps it back onto its fields. */
  onSubmit: (values: EndpointValues) => Promise<unknown>;
  onCancel?: () => void;
}

/** Create and edit share one form, so the two cannot disagree about a field. */
export function EndpointForm({
  topics,
  defaultValues,
  submitLabel,
  pending,
  onSubmit,
  onCancel,
}: EndpointFormProps) {
  const {
    register,
    control,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<EndpointValues>({ resolver: zodResolver(endpointSchema), defaultValues });
  const [formError, setFormError] = useState<string | null>(null);

  const submit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await onSubmit(values);
    } catch (error) {
      // An address the server refuses (`webhook_target_refused`) is not a
      // field-shaped error, so it lands here, at form level, with its reason.
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  return (
    <form onSubmit={submit} noValidate>
      <Stack gap="md">
        {formError ? (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {formError}
          </Alert>
        ) : null}

        <TextInput
          label="URL"
          placeholder="https://example.com/orbito"
          description="Must be https and reachable from the internet."
          required
          {...register('url')}
          error={errors.url?.message}
        />

        <TextInput
          label="Description"
          placeholder="CRM sync"
          description="Optional — so you remember what it is for."
          {...register('description')}
          error={errors.description?.message}
        />

        <Controller
          control={control}
          name="events"
          render={({ field, fieldState }) => (
            <TopicPicker
              topics={topics}
              value={field.value}
              onChange={field.onChange}
              error={fieldState.error?.message}
            />
          )}
        />

        <Group justify="flex-end">
          {onCancel ? (
            <Button variant="subtle" onClick={onCancel}>
              Cancel
            </Button>
          ) : null}
          <Button type="submit" loading={pending}>
            {submitLabel}
          </Button>
        </Group>
      </Stack>
    </form>
  );
}
