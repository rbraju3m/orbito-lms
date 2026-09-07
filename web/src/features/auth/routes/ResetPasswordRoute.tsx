import { Alert, Button, PasswordInput, Stack, Text, Title } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle } from '@tabler/icons-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate, useSearchParams } from 'react-router';

import { applyServerErrors } from '@/shared/lib/form';
import { ErrorState } from '@/shared/ui';

import { useResetPassword } from '../api/queries';
import { resetPasswordSchema, type ResetPasswordValues } from '../schemas';

export function ResetPasswordRoute() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const { mutateAsync, isPending } = useResetPassword();

  const token = params.get('token');
  const email = params.get('email');

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ResetPasswordValues>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: { password: '', password_confirmation: '' },
  });

  if (!token || !email) {
    return (
      <ErrorState
        title="This link is incomplete"
        error={new Error('Request a new password reset link and try again.')}
      />
    );
  }

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await mutateAsync({ token, email, ...values });
      void navigate('/login', { replace: true });
    } catch (error) {
      setFormError(applyServerErrors(error, setError, ['password', 'password_confirmation']));
    }
  });

  return (
    <form onSubmit={onSubmit} noValidate>
      <Stack gap="md">
        <Stack gap={4}>
          <Title order={2}>Choose a new password</Title>
          <Text c="dimmed" size="sm">
            Resetting signs you out of every device.
          </Text>
        </Stack>

        {formError ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {formError}
          </Alert>
        ) : null}

        <PasswordInput
          {...register('password')}
          label="New password"
          autoComplete="new-password"
          error={errors.password?.message}
          required
        />

        <PasswordInput
          {...register('password_confirmation')}
          label="Confirm new password"
          autoComplete="new-password"
          error={errors.password_confirmation?.message}
          required
        />

        <Button type="submit" loading={isPending} fullWidth>
          Reset password
        </Button>
      </Stack>
    </form>
  );
}
