import {
  Alert,
  Anchor,
  Button,
  Checkbox,
  PasswordInput,
  Stack,
  Text,
  TextInput,
  Title,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle } from '@tabler/icons-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useNavigate, useSearchParams } from 'react-router';

import { applyServerErrors } from '@/shared/lib/form';

import { useRegister } from '../api/queries';
import { registerSchema, type RegisterValues } from '../schemas';

const FIELDS = ['name', 'email', 'password', 'password_confirmation', 'wants_to_teach'] as const;

export function RegisterRoute() {
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const { mutateAsync, isPending } = useRegister();

  /*
   * WHICH academy this account joins. There is no anonymous surface and
   * tenancy resolves from the authenticated user, so a signup has no academy
   * unless the link carries one — every academy hands out
   * `/register?academy=<its slug>`.
   *
   * The name is not shown, deliberately: resolving a slug to a name before
   * anyone has signed up would be an endpoint for enumerating which academies
   * exist. The server names it back in its refusal when the slug is wrong.
   */
  const [searchParams] = useSearchParams();
  const academy = searchParams.get('academy')?.trim() ?? '';

  const {
    register: field,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: '',
      email: '',
      password: '',
      password_confirmation: '',
      wants_to_teach: false,
    },
  });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await mutateAsync({ ...values, academy });
      void navigate('/dashboard', { replace: true });
    } catch (error) {
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  /*
   * No academy in the link, so there is nothing to submit to. Said here rather
   * than letting the form post and come back 422 on a field the user cannot
   * see or fix.
   */
  if (academy === '') {
    return (
      <Stack gap="md">
        <Title order={2}>You need an invitation link</Title>
        <Alert color="warning" icon={<IconAlertCircle size={16} />}>
          Accounts belong to an academy, so signing up needs that academy&rsquo;s own link — it
          ends in <Text span ff="monospace">?academy=…</Text>. Ask whoever invited you for it.
        </Alert>
        <Text size="sm" c="dimmed" ta="center">
          Already have an account?{' '}
          <Anchor component={Link} to="/login">
            Sign in
          </Anchor>
        </Text>
      </Stack>
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <Stack gap="md">
        <Stack gap={4}>
          <Title order={2}>Create your account</Title>
          <Text c="dimmed" size="sm">
            Learn, or teach. You can do both from one account.
          </Text>
        </Stack>

        {formError ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {formError}
          </Alert>
        ) : null}

        <TextInput
          {...field('name')}
          label="Full name"
          autoComplete="name"
          error={errors.name?.message}
          required
        />

        <TextInput
          {...field('email')}
          label="Email"
          type="email"
          autoComplete="email"
          error={errors.email?.message}
          required
        />

        <PasswordInput
          {...field('password')}
          label="Password"
          autoComplete="new-password"
          description="At least 8 characters."
          error={errors.password?.message}
          required
        />

        <PasswordInput
          {...field('password_confirmation')}
          label="Confirm password"
          autoComplete="new-password"
          error={errors.password_confirmation?.message}
          required
        />

        <Checkbox
          {...field('wants_to_teach')}
          label="I want to teach on Orbito"
          description="We'll start an instructor application. An admin reviews it before you can publish."
        />

        <Button type="submit" loading={isPending} fullWidth>
          Create account
        </Button>

        <Text size="sm" c="dimmed" ta="center">
          Already have an account?{' '}
          <Anchor component={Link} to="/login">
            Sign in
          </Anchor>
        </Text>
      </Stack>
    </form>
  );
}
