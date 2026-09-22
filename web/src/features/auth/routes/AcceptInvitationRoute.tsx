import { Alert, Anchor, Button, PasswordInput, Stack, Text, TextInput, Title } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useNavigate, useSearchParams } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/lib/form';
import { LoadingState } from '@/shared/ui';

import { invitationPreviewQuery, useAcceptInvitation } from '../api/queries';
import { acceptInvitationSchema, type AcceptInvitationValues } from '../schemas';

const FIELDS = ['name', 'password', 'password_confirmation'] as const;

/** Codes whose answer is "sign in", not "ask for another link". */
const SIGN_IN_CODES = new Set(['invitation_accepted', 'account_exists']);

/**
 * Where an invitation's link lands (docs/INVITATIONS.md §3).
 *
 * The token arrives in the query string because a mail link has nowhere else
 * to put it; it leaves in a POST body, both to read what the link is for and
 * to accept it. The address is the invitation's and is shown, not asked for:
 * following the link is what proved it.
 */
export function AcceptInvitationRoute() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const academy = searchParams.get('academy')?.trim() ?? '';
  const token = searchParams.get('token')?.trim() ?? '';
  const complete = academy !== '' && token !== '';

  const preview = useQuery({ ...invitationPreviewQuery(academy, token), enabled: complete });
  const accept = useAcceptInvitation();
  const [formError, setFormError] = useState<ApiError | string | null>(null);

  const {
    register: field,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<AcceptInvitationValues>({
    resolver: zodResolver(acceptInvitationSchema),
    defaultValues: { name: '', password: '', password_confirmation: '' },
  });

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      await accept.mutateAsync({ ...values, academy, token });
      void navigate('/dashboard', { replace: true });
    } catch (error) {
      // A refusal about the INVITATION is not about any field on this form.
      if (error instanceof ApiError && !error.isValidation) {
        setFormError(error);
        return;
      }
      setFormError(applyServerErrors(error, setError, FIELDS));
    }
  });

  if (!complete) {
    return (
      <Refusal message="This invitation link is incomplete. Open the whole link from the email — it is long, and some mail apps split it." />
    );
  }

  if (preview.isPending) {
    return <LoadingState label="Checking your invitation" rows={3} />;
  }

  if (preview.isError) {
    return <Refusal error={preview.error} />;
  }

  const invitation = preview.data;
  const article = invitation.role === 'instructor' ? 'an' : 'a';

  return (
    <form onSubmit={onSubmit} noValidate>
      <Stack gap="md">
        <Stack gap={4}>
          <Title order={2}>Join {invitation.academy_name}</Title>
          <Text c="dimmed" size="sm">
            You have been invited as {article} {invitation.role_label.toLowerCase()}. Choose a
            password to create your account.
          </Text>
        </Stack>

        {formError instanceof ApiError ? (
          <Refusal error={formError} inline />
        ) : formError !== null ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {formError}
          </Alert>
        ) : null}

        {/* Shown, not asked for: the account takes the invited address. Kept
            as a field so a password manager saves the new password against it. */}
        <TextInput
          label="Email"
          type="email"
          autoComplete="username"
          value={invitation.email}
          readOnly
          description="The address this invitation was sent to."
        />

        <TextInput
          {...field('name')}
          label="Full name"
          autoComplete="name"
          error={errors.name?.message}
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

        <Button type="submit" loading={accept.isPending} fullWidth>
          Create account
        </Button>
      </Stack>
    </form>
  );
}

interface RefusalProps {
  error?: unknown;
  message?: string;
  /** Inside the form, under its heading, rather than a page of its own. */
  inline?: boolean;
}

/**
 * Why the link cannot be used, and what to do instead. The server tells the
 * reasons apart on purpose — only the holder of the link can ask — so the
 * page does too: an expired link needs a new one, a used one needs signing in.
 */
function Refusal({ error, message, inline = false }: RefusalProps) {
  const text =
    message ??
    (error instanceof ApiError ? error.message : 'This invitation could not be checked. Try again.');
  const signIn = error instanceof ApiError && SIGN_IN_CODES.has(error.code);

  const alert = (
    <Alert color={signIn ? 'blue' : 'warning'} icon={<IconAlertCircle size={16} />} role="alert">
      {text}
    </Alert>
  );

  if (inline) {
    return signIn ? (
      <Stack gap="xs">
        {alert}
        <Anchor component={Link} to="/login" size="sm">
          Sign in
        </Anchor>
      </Stack>
    ) : (
      alert
    );
  }

  return (
    <Stack gap="md">
      <Title order={2}>{signIn ? 'You already have an account' : 'This invitation cannot be used'}</Title>
      {alert}
      <Text size="sm" c="dimmed" ta="center">
        {signIn ? 'Your account is ready. ' : 'Already have an account? '}
        <Anchor component={Link} to="/login">
          Sign in
        </Anchor>
      </Text>
    </Stack>
  );
}
