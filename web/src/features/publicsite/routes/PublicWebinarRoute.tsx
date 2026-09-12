import { Badge, Button, Card, Group, Stack, Text, Title } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';

import { ErrorState, LoadingState } from '@/shared/ui';
import { formatMinor } from '@/shared/lib/money';

import { publicAcademyQuery, publicWebinarQuery } from '../api/queries';

/**
 * A webinar's public page — what `Webinar`'s docblock has been waiting for
 * since P15: the one live format not gated on buying a course, finally
 * readable by somebody who is not a member.
 *
 * REGISTERING still needs an account, and the page says so rather than
 * offering a form that cannot work. A guest place — an email with no account —
 * is a real feature and a bigger one than it looks: nothing could tell that
 * person the event had been called off, because delivery is to an account.
 */
export function PublicWebinarRoute() {
  const { academy = '', slug = '' } = useParams();

  const academyQuery = useQuery(publicAcademyQuery(academy));
  const { data, isPending, isError, error } = useQuery(publicWebinarQuery(academy, slug));

  if (isPending) {
    return <LoadingState label="Loading event" rows={3} />;
  }

  if (isError) {
    return <ErrorState error={error} title="This event is not available" />;
  }

  const session = data.session;
  const price = data.price;
  const full = data.places_remaining === 0;

  return (
    <Stack gap="lg" p="md" maw={760} mx="auto">
      <Stack gap="xs">
        <Title order={1}>{data.title}</Title>

        {session ? (
          <Group gap="xs">
            <Text c="dimmed">{new Date(session.starts_at).toLocaleString()}</Text>
            <Text c="dimmed">({session.timezone})</Text>
          </Group>
        ) : null}

        <Group gap="xs">
          {data.is_paid ? <Badge variant="light">Ticketed</Badge> : <Badge variant="light" color="gray">Free</Badge>}
          {/* A number, not "full": somebody deciding whether to sign up now
              wants to know it is nearly gone. */}
          {data.places_remaining !== null ? (
            <Badge variant="outline" color={full ? 'warning' : 'gray'}>
              {full ? 'No places left' : `${data.places_remaining} places left`}
            </Badge>
          ) : null}
        </Group>
      </Stack>

      {data.description ? <Text>{data.description}</Text> : null}

      <Card withBorder padding="lg">
        <Stack gap="sm">
          {data.is_paid && price != null ? (
            <Text fw={600} size="lg">
              {formatMinor(price.amount_minor, price.currency)}
            </Text>
          ) : null}

          {academyQuery.data?.registration_open === true ? (
            <Button component={Link} to={`/register?academy=${encodeURIComponent(academy)}`}>
              Create an account to register
            </Button>
          ) : (
            <Text size="sm" c="dimmed">
              Registering needs an account, and this academy is not taking new
              registrations at the moment.
            </Text>
          )}

          <Button
            component={Link}
            to={`/login?academy=${encodeURIComponent(academy)}`}
            variant="subtle"
          >
            Already a member? Sign in
          </Button>
        </Stack>
      </Card>
    </Stack>
  );
}
