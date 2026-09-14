import { Alert, Button, Stack, Text, TextInput, Title } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery } from '@tanstack/react-query';
import { useState, type ReactNode } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { ApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/lib/form';
import { ErrorState, LoadingState } from '@/shared/ui';

import { publicFormTokenQuery, useRequestGuestPlace } from '../api/guest';
import { HoneypotField } from './HoneypotField';

/** Mirrors `RequestGuestRegistrationRequest`; the server still decides. */
const guestSchema = z.object({
  email: z
    .string()
    .trim()
    .min(1, 'Enter your email address.')
    .max(191, 'That address is too long.')
    .email('That does not look like an email address.'),
  name: z.string().trim().max(160, 'Keep it under 160 characters.'),
  website: z.string(),
});

type GuestValues = z.infer<typeof guestSchema>;

const FIELDS = ['email', 'name'] as const;

export interface GuestRegistrationFormProps {
  academy: string;
  slug: string;
}

/**
 * A place at a free event with no account (docs/GUEST_REGISTRATION.md).
 *
 * The form books nothing. It asks the server to email a confirmation link,
 * and the server answers the same way whether that address already holds a
 * place, has asked too often, or tripped a trap — so the success message
 * says only what is always true: look in your inbox.
 */
export function GuestRegistrationForm({ academy, slug }: GuestRegistrationFormProps) {
  const form = useQuery(publicFormTokenQuery(academy));
  const request = useRequestGuestPlace(academy, slug);
  const [formError, setFormError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<GuestValues>({
    resolver: zodResolver(guestSchema),
    defaultValues: { email: '', name: '', website: '' },
  });

  const submit = handleSubmit(async (values) => {
    if (form.data === undefined) return;

    setFormError(null);

    try {
      await request.mutateAsync({
        email: values.email,
        name: values.name === '' ? null : values.name,
        form_token: form.data.token,
        website: values.website,
      });
      setSent(true);
    } catch (error) {
      if (error instanceof ApiError && error.isValidation && 'form_token' in error.fieldErrors()) {
        void form.refetch();
        setFormError(
          'This form had been open a long time, so we refreshed it. Please send it again.',
        );
        return;
      }

      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  let body: ReactNode;

  if (sent) {
    body = (
      <Text role="status">
        Check your inbox. If a place can be held for that address, we have sent a link to confirm it
        — nothing is booked until you follow it.
      </Text>
    );
  } else if (form.isPending) {
    body = <LoadingState label="Loading form" rows={2} />;
  } else if (form.isError) {
    body = (
      <ErrorState
        error={form.error}
        title="The form could not be loaded"
        onRetry={() => void form.refetch()}
      />
    );
  } else {
    body = (
      <form onSubmit={submit} noValidate>
        <Stack gap="sm">
          <Text size="sm" c="dimmed">
            No account needed. We will email you a link to confirm your place, and the same email
            lets you join or give the place up.
          </Text>

          <TextInput
            label="Email"
            type="email"
            autoComplete="email"
            required
            error={errors.email?.message}
            {...register('email')}
          />
          <TextInput
            label="Name"
            description="Optional"
            autoComplete="name"
            error={errors.name?.message}
            {...register('name')}
          />

          <HoneypotField {...register('website')} />

          {formError !== null ? (
            <Alert color="red" role="alert">
              {formError}
            </Alert>
          ) : null}

          <Button type="submit" loading={isSubmitting} style={{ alignSelf: 'flex-start' }}>
            Email me a link
          </Button>
        </Stack>
      </form>
    );
  }

  return (
    <Stack gap="sm">
      <Title order={2} size="h4">
        Hold a place
      </Title>
      {body}
    </Stack>
  );
}
