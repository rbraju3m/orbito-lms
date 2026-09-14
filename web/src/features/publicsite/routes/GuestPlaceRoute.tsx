import { Alert, Anchor, Badge, Button, Card, Group, Stack, Text, Title } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router';

import { ErrorState, LoadingState } from '@/shared/ui';

import { guestPlaceQuery, useCancelGuestPlace, useJoinGuestPlace } from '../api/guest';
import { guestProblem } from '../lib/guest';

/**
 * A guest's place, opened from the link in any of their emails
 * (docs/GUEST_REGISTRATION.md). There is no account, so this page is the
 * whole of what they can do: see it, join it, give it up.
 *
 * What may be done comes from `can_join` and `can_cancel` — the server's
 * answer from the rules its endpoints enforce — so a button that would be
 * refused is never drawn.
 */
export function GuestPlaceRoute() {
  const { academy = '', slug = '' } = useParams();
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';

  const place = useQuery({ ...guestPlaceQuery(academy, token), enabled: token !== '' });
  const cancel = useCancelGuestPlace(academy, token);
  const join = useJoinGuestPlace(academy, token);
  const [confirmingCancel, setConfirmingCancel] = useState(false);

  const eventPath = `/a/${academy}/webinars/${slug}`;

  if (token === '') {
    return (
      <Stack p="md" maw={640} mx="auto">
        <Alert color="yellow" role="note">
          This link is incomplete. Open it straight from the email.
        </Alert>
      </Stack>
    );
  }

  if (place.isPending) {
    return <LoadingState label="Loading your place" rows={3} />;
  }

  if (place.isError) {
    const problem = guestProblem(place.error);

    return (
      <Stack p="md" maw={640} mx="auto">
        {problem === null ? (
          <ErrorState error={place.error} title="Your place could not be loaded" />
        ) : (
          <Card withBorder padding="lg">
            <Stack gap="sm">
              <Text>{problem}</Text>
              <Anchor component={Link} to={eventPath}>
                Back to the event
              </Anchor>
            </Stack>
          </Card>
        )}
      </Stack>
    );
  }

  const { webinar } = place.data;
  const session = webinar.session;
  const held = place.data.status === 'registered';
  const actionProblem = guestProblem(cancel.error ?? join.error);

  return (
    <Stack p="md" maw={640} mx="auto">
      <Card withBorder padding="lg">
        <Stack gap="md">
          <Stack gap="xs">
            <Title order={1} size="h2">
              {webinar.title}
            </Title>
            {session ? (
              <Text c="dimmed">
                {new Date(session.starts_at).toLocaleString()} ({session.timezone})
              </Text>
            ) : null}
            <Group gap="xs">
              <Badge color={held ? 'green' : 'gray'} variant="light">
                {held ? 'You hold a place' : 'Place given up'}
              </Badge>
              <Text size="sm" c="dimmed">
                {place.data.email}
              </Text>
            </Group>
          </Stack>

          {actionProblem !== null ? (
            <Alert color="red" role="alert">
              {actionProblem}
            </Alert>
          ) : null}

          {place.data.can_join ? (
            join.data ? (
              <Button
                component="a"
                href={join.data.join_url}
                target="_blank"
                rel="noopener noreferrer"
              >
                Open the meeting
              </Button>
            ) : (
              <Button
                loading={join.isPending}
                onClick={() => join.mutate()}
                style={{ alignSelf: 'flex-start' }}
              >
                Join now
              </Button>
            )
          ) : null}

          {held && !place.data.can_join ? (
            <Text size="sm">
              A join button appears on this page shortly before the event starts. Keep the email —
              this link is how you get in.
            </Text>
          ) : null}

          {!held ? (
            <Text size="sm">
              You gave this place up. If you change your mind, ask for a place again from the{' '}
              <Anchor component={Link} to={eventPath}>
                event page
              </Anchor>
              .
            </Text>
          ) : null}

          {place.data.can_cancel ? (
            confirmingCancel ? (
              <Group gap="xs">
                <Text size="sm">Give your place up? Somebody else can then have it.</Text>
                <Button
                  color="red"
                  size="xs"
                  loading={cancel.isPending}
                  onClick={() =>
                    cancel.mutate(undefined, { onSuccess: () => setConfirmingCancel(false) })
                  }
                >
                  Yes, give it up
                </Button>
                <Button variant="default" size="xs" onClick={() => setConfirmingCancel(false)}>
                  Keep it
                </Button>
              </Group>
            ) : (
              <Button
                variant="subtle"
                color="red"
                onClick={() => setConfirmingCancel(true)}
                style={{ alignSelf: 'flex-start' }}
              >
                Give up my place
              </Button>
            )
          ) : null}
        </Stack>
      </Card>
    </Stack>
  );
}
