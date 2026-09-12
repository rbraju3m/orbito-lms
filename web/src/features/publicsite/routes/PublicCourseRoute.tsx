import { Badge, Button, Card, Group, Image, Stack, Text, Title } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';

import { ErrorState, LoadingState } from '@/shared/ui';
import { formatMinor } from '@/shared/lib/money';

import { publicAcademyQuery, publicCourseQuery } from '../api/queries';

/**
 * A course's sales page, readable with no account.
 *
 * It renders the same `CourseResource` the members-only page does, and the
 * difference is entirely in what that resource omits for a viewer who is null:
 * no wishlist, no staff settings, no publish checklist. Nothing is stripped
 * here, which is the property `PublicSiteTest` pins — a new viewer-scoped
 * field cannot leak onto this page by somebody forgetting about it.
 *
 * The call to action is to SIGN UP, not to buy. Enrolling and paying both
 * need an account, and pretending otherwise would send a stranger into a
 * checkout that 401s.
 */
export function PublicCourseRoute() {
  const { academy = '', slug = '' } = useParams();

  const academyQuery = useQuery(publicAcademyQuery(academy));
  const { data, isPending, isError, error } = useQuery(publicCourseQuery(academy, slug));

  if (isPending) {
    return <LoadingState label="Loading course" rows={4} />;
  }

  if (isError) {
    return <ErrorState error={error} title="This course is not available" />;
  }

  const price = data.price;

  return (
    <Stack gap="lg" p="md" maw={900} mx="auto">
      <Group gap="xs">
        {data.category ? (
          <Badge variant="light">{data.category.name}</Badge>
        ) : null}
        <Badge variant="outline" color="gray">
          {data.level_label}
        </Badge>
      </Group>

      <Stack gap="xs">
        <Title order={1}>{data.title}</Title>
        {data.subtitle ? <Text size="lg" c="dimmed">{data.subtitle}</Text> : null}
      </Stack>

      {data.thumbnail ? <Image src={data.thumbnail} alt="" radius="md" mah={360} fit="cover" /> : null}

      {data.description ? <Text>{data.description}</Text> : null}

      <Card withBorder padding="lg">
        <Stack gap="sm">
          <Group justify="space-between" align="baseline">
            <Text fw={600} size="lg">
              {price == null
                ? 'Free'
                : formatMinor(price.amount_minor, price.currency)}
            </Text>
            {price?.is_on_sale === true && price.list_amount_minor !== null ? (
              <Text size="sm" c="dimmed" td="line-through">
                {formatMinor(price.list_amount_minor, price.currency)}
              </Text>
            ) : null}
          </Group>

          {academyQuery.data?.registration_open === true ? (
            <Button component={Link} to={`/register?academy=${encodeURIComponent(academy)}`}>
              Create an account to enrol
            </Button>
          ) : (
            <Text size="sm" c="dimmed">
              This academy is not taking new registrations at the moment.
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
