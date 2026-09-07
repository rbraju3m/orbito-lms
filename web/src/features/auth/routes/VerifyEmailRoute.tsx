import { Alert, Anchor, Button, Center, Loader, Stack, Text, Title } from '@mantine/core';
import { IconCircleCheck } from '@tabler/icons-react';
import { useEffect, useRef } from 'react';
import { Link, useLocation } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { ErrorState } from '@/shared/ui';

import { useVerifyEmail } from '../api/queries';

/**
 * The emailed link points here. The signed query string is replayed to the API
 * verbatim — re-encoding it would invalidate the signature.
 */
export function VerifyEmailRoute() {
  const location = useLocation();
  const { mutate, isPending, isSuccess, isError, error } = useVerifyEmail();
  const attempted = useRef(false);

  useEffect(() => {
    // StrictMode mounts effects twice in development; verifying twice would
    // turn a success into an "already verified" conflict.
    if (attempted.current || !location.search) return;
    attempted.current = true;
    mutate(location.search);
  }, [location.search, mutate]);

  if (!location.search) {
    return (
      <ErrorState
        title="This link is incomplete"
        error={new Error('Open the link from your email, or request a new one.')}
      />
    );
  }

  if (isPending) {
    return (
      <Center py="xl">
        <Loader aria-label="Verifying your email address" />
      </Center>
    );
  }

  if (isSuccess) {
    return (
      <Stack gap="md">
        <Alert color="success" icon={<IconCircleCheck size={16} />} role="status">
          Your email address is verified.
        </Alert>
        <Button component={Link} to="/dashboard" fullWidth>
          Go to your dashboard
        </Button>
      </Stack>
    );
  }

  if (isError) {
    const alreadyVerified = error instanceof ApiError && error.code === 'email_already_verified';

    return (
      <Stack gap="md">
        <Title order={2}>
          {alreadyVerified ? 'Already verified' : 'We could not verify that link'}
        </Title>
        <Text c="dimmed" size="sm">
          {alreadyVerified
            ? 'This address was verified already. You can sign in as normal.'
            : 'Verification links expire after an hour. Sign in and request a new one.'}
        </Text>
        <Anchor component={Link} to="/login">
          Go to sign in
        </Anchor>
      </Stack>
    );
  }

  return null;
}
