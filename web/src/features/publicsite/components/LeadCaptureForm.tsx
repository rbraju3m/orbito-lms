import { Alert, Button, Card, Checkbox, Stack, Text, TextInput, Title } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQuery } from '@tanstack/react-query';
import { useId, useState, type CSSProperties, type ReactNode } from 'react';
import { useForm } from 'react-hook-form';

import { ApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/lib/form';
import { ErrorState, LoadingState } from '@/shared/ui';

import { publicLeadFormQuery, useSubmitLead, type LeadSource } from '../api/leads';
import { leadSchema, type LeadValues } from '../leadSchema';

const FIELDS = ['email', 'name', 'consent'] as const;

/*
 * Off-screen rather than `display: none`: a script that skips invisible
 * inputs still fills one that is merely somewhere else. Out of the tab order
 * and hidden from assistive technology, so no person meets it.
 */
const HONEYPOT: CSSProperties = {
  position: 'absolute',
  left: '-10000px',
  top: 'auto',
  width: 1,
  height: 1,
  overflow: 'hidden',
};

export interface LeadCaptureFormProps {
  academy: string;
  source: LeadSource;
  /** The course or event slug, for every source but the front page. */
  sourceSlug?: string;
  title?: string;
  description?: string;
}

/**
 * "Keep me posted", for somebody with no account (docs/LEADS.md).
 *
 * The consent wording is the SERVER's, because the server stores a copy of it
 * with the lead; a label written here would be a record of agreement to words
 * nobody on the server can vouch for.
 *
 * The success message promises nothing about what happened, because the
 * server does not say: a new address, one already on the list and a tripped
 * trap all get the same answer.
 */
export function LeadCaptureForm({
  academy,
  source,
  sourceSlug,
  title = 'Stay in touch',
  description = 'Leave your email and we will tell you when something new is announced.',
}: LeadCaptureFormProps) {
  const headingId = useId();
  const form = useQuery(publicLeadFormQuery(academy));
  const submitLead = useSubmitLead(academy);
  const [formError, setFormError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<LeadValues>({
    resolver: zodResolver(leadSchema),
    defaultValues: { email: '', name: '', consent: false, website: '' },
  });

  const submit = handleSubmit(async (values) => {
    if (form.data === undefined) return;

    setFormError(null);

    try {
      await submitLead.mutateAsync({
        email: values.email,
        name: values.name === '' ? null : values.name,
        consent: values.consent,
        source,
        ...(sourceSlug === undefined ? {} : { source_slug: sourceSlug }),
        form_token: form.data.token,
        website: values.website,
      });
      setSent(true);
    } catch (error) {
      // The one rejection a person can fix without retyping anything: the
      // form sat open past its token's lifetime. Fetch a fresh one.
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
    body = <Text role="status">Thank you — we will be in touch.</Text>;
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
            {description}
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

          <div aria-hidden="true" style={HONEYPOT}>
            <label>
              Website
              <input type="text" tabIndex={-1} autoComplete="off" {...register('website')} />
            </label>
          </div>

          <Checkbox
            label={form.data.consent_text}
            error={errors.consent?.message}
            {...register('consent')}
          />

          {formError !== null ? (
            <Alert color="red" role="alert">
              {formError}
            </Alert>
          ) : null}

          <Button type="submit" loading={isSubmitting} style={{ alignSelf: 'flex-start' }}>
            Keep me posted
          </Button>
        </Stack>
      </form>
    );
  }

  return (
    <Card withBorder padding="lg" component="section" aria-labelledby={headingId}>
      <Stack gap="sm">
        <Title order={2} size="h4" id={headingId}>
          {title}
        </Title>
        {body}
      </Stack>
    </Card>
  );
}
