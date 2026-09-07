import {
  Alert,
  Button,
  Card,
  Container,
  Group,
  PasswordInput,
  Stack,
  Text,
  Title,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle, IconCheck } from '@tabler/icons-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import { changePasswordSchema, type ChangePasswordValues } from '@/features/auth/schemas';
import { applyServerErrors } from '@/shared/lib/form';
import { PageHeader } from '@/shared/ui';

import { useChangePassword } from '../api/queries';

const FIELDS = ['current_password', 'password', 'password_confirmation'] as const;

export function SecurityRoute() {
  const { mutateAsync, isPending } = useChangePassword();
  const [formError, setFormError] = useState<string | null>(null);
  const [changed, setChanged] = useState(false);

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<ChangePasswordValues>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    setChanged(false);
    try {
      await mutateAsync(values);
      setChanged(true);
      reset();
    } catch (error) {
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  return (
    <Container size="sm" py="lg">
      <PageHeader title="Security" description="Keep your account safe." />

      <Card>
        <form onSubmit={onSubmit} noValidate>
          <Stack gap="md">
            <Title order={3}>Change password</Title>
            <Text size="sm" c="dimmed">
              Changing your password signs you out of every other device.
            </Text>

            {formError ? (
              <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
                {formError}
              </Alert>
            ) : null}

            {changed ? (
              <Alert color="success" icon={<IconCheck size={16} />} role="status">
                Password changed.
              </Alert>
            ) : null}

            <PasswordInput
              {...register('current_password')}
              label="Current password"
              autoComplete="current-password"
              error={errors.current_password?.message}
              required
            />
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

            <Group justify="flex-end">
              <Button type="submit" loading={isPending}>
                Change password
              </Button>
            </Group>
          </Stack>
        </form>
      </Card>
    </Container>
  );
}
