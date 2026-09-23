import {
  Alert,
  Button,
  Card,
  Container,
  Group,
  Select,
  Stack,
  Textarea,
  TextInput,
  Title,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle, IconCheck } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';

import { sessionQuery } from '@/features/auth/api/queries';
import { profileSchema, type ProfileValues } from '@/features/auth/schemas';
import { applyServerErrors } from '@/shared/lib/form';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { profileQuery, useUpdateProfile } from '../api/queries';
import { InstructorApplicationCard } from './InstructorApplicationCard';

const FIELDS = ['name', 'headline', 'bio', 'timezone', 'locale'] as const;

export function ProfileRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(profileQuery());
  const { data: session } = useQuery(sessionQuery());
  const { mutateAsync, isPending: isSaving } = useUpdateProfile();
  const [formError, setFormError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const {
    register,
    control,
    handleSubmit,
    reset,
    setError,
    formState: { errors, isDirty },
  } = useForm<ProfileValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: { name: '', headline: '', bio: '', timezone: 'UTC', locale: '' },
  });

  useEffect(() => {
    if (!data) return;
    reset({
      name: data.name,
      headline: data.headline ?? '',
      bio: data.bio ?? '',
      timezone: data.timezone,
      locale: data.locale ?? '',
    });
  }, [data, reset]);

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    setSaved(false);
    try {
      await mutateAsync({ ...values, locale: values.locale === '' ? null : values.locale });
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
                <Title order={2} size="h3">
                  Details
                </Title>

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
                  <Controller
                    control={control}
                    name="locale"
                    render={({ field }) => (
                      <Select
                        label="Language"
                        // Only what this academy offers: the server refuses
                        // anything else (`locale.available` on the session).
                        data={[
                          { value: '', label: "The academy's language" },
                          ...(session?.locale.available ?? []).map((locale) => ({
                            value: locale.code,
                            label: locale.native_name,
                          })),
                        ]}
                        value={field.value}
                        onChange={(value) => field.onChange(value ?? '')}
                        onBlur={field.onBlur}
                        allowDeselect={false}
                        error={errors.locale?.message}
                      />
                    )}
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
