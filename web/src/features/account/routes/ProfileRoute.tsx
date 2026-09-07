import {
  Alert,
  Button,
  Card,
  Container,
  Group,
  Stack,
  Textarea,
  TextInput,
  Title,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle, IconCheck } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';

import { profileSchema, type ProfileValues } from '@/features/auth/schemas';
import { applyServerErrors } from '@/shared/lib/form';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { profileQuery, useUpdateProfile } from '../api/queries';
import { InstructorApplicationCard } from './InstructorApplicationCard';

const FIELDS = ['name', 'headline', 'bio', 'timezone', 'locale'] as const;

export function ProfileRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(profileQuery());
  const { mutateAsync, isPending: isSaving } = useUpdateProfile();
  const [formError, setFormError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isDirty },
  } = useForm<ProfileValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: { name: '', headline: '', bio: '', timezone: 'UTC', locale: 'en' },
  });

  useEffect(() => {
    if (!data) return;
    reset({
      name: data.name,
      headline: data.headline ?? '',
      bio: data.bio ?? '',
      timezone: data.timezone,
      locale: data.locale,
    });
  }, [data, reset]);

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    setSaved(false);
    try {
      await mutateAsync(values);
      setSaved(true);
    } catch (err) {
      setFormError(applyServerErrors(err, setError, FIELDS));
    }
  });

  return (
    <Container size="sm" py="lg">
      <PageHeader title="Profile" description="How you appear across Orbito." />

      {isPending ? <LoadingState rows={4} /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data ? (
        <Stack gap="lg">
          <Card>
            <form onSubmit={onSubmit} noValidate>
              <Stack gap="md">
                <Title order={3}>Details</Title>

                {formError ? (
                  <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
                    {formError}
                  </Alert>
                ) : null}

                {saved ? (
                  <Alert color="success" icon={<IconCheck size={16} />} role="status">
                    Profile saved.
                  </Alert>
                ) : null}

                <TextInput
                  {...register('name')}
                  label="Full name"
                  error={errors.name?.message}
                  required
                />
                <TextInput
                  {...register('headline')}
                  label="Headline"
                  placeholder="Mathematician, teacher"
                  error={errors.headline?.message}
                />
                <Textarea
                  {...register('bio')}
                  label="Bio"
                  autosize
                  minRows={3}
                  maxRows={8}
                  error={errors.bio?.message}
                />
                <Group grow>
                  <TextInput
                    {...register('timezone')}
                    label="Timezone"
                    error={errors.timezone?.message}
                  />
                  <TextInput
                    {...register('locale')}
                    label="Language"
                    error={errors.locale?.message}
                  />
                </Group>

                <Group justify="flex-end">
                  <Button type="submit" loading={isSaving} disabled={!isDirty}>
                    Save changes
                  </Button>
                </Group>
              </Stack>
            </form>
          </Card>

          <InstructorApplicationCard />
        </Stack>
      ) : null}
    </Container>
  );
}
