import { Alert, Button, Group, Modal, PasswordInput, Select, Stack, TextInput } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { applyServerErrors } from '@/shared/lib/form';

import { plansQuery, useProvisionAcademy } from '../api/queries';
import type { Tenant } from '../api/types';

/**
 * Mirrors `StoreTenantRequest`. Where the two differ, the SERVER wins — this
 * exists to answer before a round trip, not to define the rule.
 *
 * The password minimum is 10 rather than the 8 the server accepts outside
 * production, deliberately: `Password::defaults()` tightens to 10 with letters
 * and numbers in production, and a form that accepts a password the live
 * system will refuse is worse than one that is a little strict locally.
 */
const schema = z.object({
  slug: z
    .string()
    .min(3, 'At least 3 characters.')
    .max(100)
    .regex(
      /^[a-z0-9]+(-[a-z0-9]+)*$/,
      'Lowercase letters, numbers and single hyphens only — no spaces.',
    ),
  name: z.string().min(2, 'Give the academy a name.').max(180),
  owner_name: z.string().min(2, 'Who runs it?').max(255),
  owner_email: z.string().email('That does not look like an email address.').max(255),
  owner_password: z
    .string()
    .min(10, 'At least 10 characters.')
    .regex(/[a-zA-Z]/, 'Include at least one letter.')
    .regex(/[0-9]/, 'Include at least one number.'),
  // Optional means an EMPTY string is legal, not that the field is absent —
  // the input always sends one. A bare `.optional()` would reject ''.
  support_email: z.union([
    z.string().email('That does not look like an email address.'),
    z.literal(''),
  ]),
  plan: z.string().optional(),
});

type Values = z.infer<typeof schema>;

const FIELDS = [
  'slug',
  'name',
  'owner_name',
  'owner_email',
  'owner_password',
  'support_email',
  'plan',
] as const;

interface Props {
  opened: boolean;
  onClose: () => void;
  onProvisioned: (tenant: Tenant) => void;
}

export function ProvisionAcademyModal({ opened, onClose, onProvisioned }: Props) {
  const [formError, setFormError] = useState<string | null>(null);
  const { mutateAsync, isPending } = useProvisionAcademy();
  const { data: plans } = useQuery(plansQuery());

  const {
    register,
    handleSubmit,
    setValue,
    setError,
    reset,
    formState: { errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      slug: '',
      name: '',
      owner_name: '',
      owner_email: '',
      owner_password: '',
      support_email: '',
    },
  });

  const planOptions = (plans ?? [])
    .filter((plan) => plan.is_active)
    .map((plan) => ({ value: plan.slug, label: plan.name }));

  const close = () => {
    reset();
    setFormError(null);
    onClose();
  };

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);

    try {
      const tenant = await mutateAsync({
        slug: values.slug,
        name: values.name,
        owner_name: values.owner_name,
        owner_email: values.owner_email,
        owner_password: values.owner_password,
        ...(values.support_email ? { support_email: values.support_email } : {}),
        ...(values.plan ? { plan: values.plan } : {}),
      });

      reset();
      onProvisioned(tenant);
    } catch (error) {
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  return (
    <Modal opened={opened} onClose={close} title="New academy" centered size="lg">
      <form onSubmit={onSubmit} noValidate>
        <Stack gap="md">
          {formError ? (
            <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
              {formError}
            </Alert>
          ) : null}

          <TextInput
            {...register('name')}
            label="Academy name"
            placeholder="North College"
            error={errors.name?.message}
            required
            autoFocus
          />

          <TextInput
            {...register('slug')}
            label="Slug"
            description="Names the academy and its database for its whole life. It cannot be changed later."
            placeholder="north-college"
            error={errors.slug?.message}
            required
          />

          <TextInput
            {...register('owner_name')}
            label="Owner’s name"
            error={errors.owner_name?.message}
            required
          />

          <TextInput
            {...register('owner_email')}
            type="email"
            label="Owner’s email"
            description="They become the academy’s admin. The address must not already have an account."
            error={errors.owner_email?.message}
            required
          />

          <PasswordInput
            {...register('owner_password')}
            label="Owner’s password"
            description="Give it to them out of band, and tell them to change it."
            error={errors.owner_password?.message}
            required
          />

          <TextInput
            {...register('support_email')}
            type="email"
            label="Support email"
            description="Optional. Shown to the academy’s own members."
            error={errors.support_email?.message}
          />

          <Select
            data={planOptions}
            onChange={(value) => setValue('plan', value ?? undefined)}
            label="Plan"
            placeholder="The default plan"
            description="Optional. Leave empty to use the first active plan."
            error={errors.plan?.message}
            clearable
          />

          <Alert color="warning" variant="light">
            The academy is created <strong>shut</strong>. Provisioning builds its database; you
            approve it separately, and nobody can sign in until you do.
          </Alert>

          <Group justify="flex-end">
            <Button variant="default" onClick={close} disabled={isPending}>
              Cancel
            </Button>
            <Button type="submit" loading={isPending}>
              Create academy
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
