import { Alert, Anchor, Button, Stack, Text, TextInput, Title } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle, IconMailCheck } from '@tabler/icons-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router';

import { applyServerErrors } from '@/shared/lib/form';

import { useForgotPassword } from '../api/queries';
import { forgotPasswordSchema, type ForgotPasswordValues } from '../schemas';

export function ForgotPasswordRoute() {
  const [formError, setFormError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const { mutateAsync, isPending } = useForgotPassword();

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ForgotPasswordValues>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: { email: '' },
  });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await mutateAsync(values.email);
      setSent(true);
    } catch (error) {
      setFormError(applyServerErrors(error, setError, ['email']));
    }
  });

  // The server deliberately answers the same way for known and unknown
  // addresses, so this screen must too — anything else re-opens the
  // enumeration hole the API closed.
  if (sent) {
    return (
      <Stack gap="md">
        <Alert color="success" icon={<IconMailCheck size={16} />} role="status">
          If an account exists for that address, a reset link is on its way.
        </Alert>
        <Anchor component={Link} to="/login" ta="center">
          Back to sign in
        </Anchor>
      </Stack>
    );
  }

  return (
    <form onSubmit={onSubmit} noValidate>
      <Stack gap="md">
        <Stack gap={4}>
          <Title order={2}>Reset your password</Title>
          <Text c="dimmed" size="sm">
            Enter your email and we'll send you a link.
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

        <Button type="submit" loading={isPending} fullWidth>
          Send reset link
        </Button>

        <Anchor component={Link} to="/login" size="sm" ta="center">
          Back to sign in
        </Anchor>
      </Stack>
    </form>
  );
}
