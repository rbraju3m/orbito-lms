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
import { Link, useLocation, useNavigate } from 'react-router';

import { applyServerErrors } from '@/shared/lib/form';

import { useLogin } from '../api/queries';
import { loginSchema, type LoginValues } from '../schemas';

const FIELDS = ['email', 'password', 'device_name'] as const;

export function LoginRoute() {
  const navigate = useNavigate();
  const location = useLocation();
  const [formError, setFormError] = useState<string | null>(null);
  const { mutateAsync, isPending } = useLogin();

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '', remember: false },
  });

  const redirectTo = (location.state as { from?: string } | null)?.from ?? '/dashboard';

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await mutateAsync(values);
      void navigate(redirectTo, { replace: true });
    } catch (error) {
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate>
      <Stack gap="md">
        <Stack gap={4}>
          <Title order={2}>Welcome back</Title>
          <Text c="dimmed" size="sm">
            Sign in to continue learning or teaching.
          </Text>
        </Stack>

        {formError ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {formError}
          </Alert>
        ) : null}

        <TextInput
          {...register('email')}
          label="Email"
          type="email"
          autoComplete="email"
          error={errors.email?.message}
          required
        />

        <PasswordInput
          {...register('password')}
          label="Password"
          autoComplete="current-password"
          error={errors.password?.message}
          required
        />

        <Checkbox {...register('remember')} label="Keep me signed in" />

        <Button type="submit" loading={isPending} fullWidth>
          Sign in
        </Button>

        <Stack gap={4} align="center">
          <Anchor component={Link} to="/forgot-password" size="sm">
            Forgot your password?
          </Anchor>
          <Text size="sm" c="dimmed">
            New here?{' '}
            <Anchor component={Link} to="/register">
              Create an account
            </Anchor>
          </Text>
        </Stack>
      </Stack>
    </form>
  );
}
