import { Alert, Anchor, Button, Card, Stack, Text, Title } from '@mantine/core';
import { useEffect, useRef } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';

import { ErrorState, LoadingState } from '@/shared/ui';

import { useConfirmGuestPlace } from '../api/guest';
import { guestProblem } from '../lib/guest';

/**
 * Where the confirmation link in a guest's email lands
 * (docs/GUEST_REGISTRATION.md). Following it is what holds the place.
 *
 * The confirm is a POST fired ONCE on arrival, guarded by a ref: React 19 runs
 * effects twice in development, and the server treats a second confirm as a
 * repeat — harmless, but the page should not depend on that.
 */
export function GuestConfirmRoute() {
  const { academy = '', slug = '' } = useParams();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const { mutate, data, error, isError, isSuccess } = useConfirmGuestPlace(academy);
  const started = useRef(false);

  useEffect(() => {
    if (started.current || token === '') return;
    started.current = true;
    mutate(token);
  }, [mutate, token]);

  const eventPath = `/a/${academy}/webinars/${slug}`;

  let body;

  if (token === '') {
    body = (
      <Alert color="yellow" role="note">
        This link is incomplete. Open it straight from the email, or ask for a new one from the{' '}
        <Anchor component={Link} to={eventPath}>
          event page
        </Anchor>
        .
      </Alert>
    );
  } else if (isError) {
    const problem = guestProblem(error);

    body =
      problem === null ? (
        <ErrorState error={error} title="Your place could not be confirmed" />
      ) : (
        <Stack gap="sm">
          <Text>{problem}</Text>
          <Anchor component={Link} to={eventPath}>
            Back to the event
          </Anchor>
        </Stack>
      );
  } else if (isSuccess) {
    const session = data.webinar.session;

    body = (
      <Stack gap="sm">
        <Title order={1} size="h2">
          You are registered
        </Title>
        <Text>
          Your place at <strong>{data.webinar.title}</strong> is confirmed
          {session ? `, ${new Date(session.starts_at).toLocaleString()} (${session.timezone})` : ''}
          .
        </Text>
        <Text size="sm" c="dimmed">
          We have emailed you the link below as well. Use it to join when it starts, or to give your
          place up.
        </Text>
        <Button
          component={Link}
          to={`/a/${academy}/webinars/${slug}/place?token=${encodeURIComponent(data.token)}`}
          style={{ alignSelf: 'flex-start' }}
        >
          Manage my place
        </Button>
      </Stack>
    );
  } else {
    body = <LoadingState label="Confirming your place" rows={2} />;
  }

  return (
    <Stack p="md" maw={640} mx="auto">
      <Card withBorder padding="lg">
        {body}
      </Card>
    </Stack>
  );
}
