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
import { Link, useNavigate } from 'react-router';

import { applyServerErrors } from '@/shared/lib/form';

import { useRegister } from '../api/queries';
import { registerSchema, type RegisterValues } from '../schemas';

const FIELDS = ['name', 'email', 'password', 'password_confirmation', 'wants_to_teach'] as const;

export function RegisterRoute() {
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const { mutateAsync, isPending } = useRegister();

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
      await mutateAsync(values);
      void navigate('/dashboard', { replace: true });
    } catch (error) {
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

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
