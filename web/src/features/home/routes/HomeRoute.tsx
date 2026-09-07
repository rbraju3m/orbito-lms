import { Anchor, Card, Container, List, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router';

export function HomeRoute() {
  return (
    <Container size="md" py="xl">
      <Stack gap="lg">
        <Stack gap="xs">
          <Title order={1}>Orbito LMS</Title>
          <Text c="dimmed">
            Phase 2 — foundation. The API, the design system, server state and the test harness are
            in place; product features start in Phase 3.
          </Text>
        </Stack>

        <Card>
          <Stack gap="xs">
            <Title order={3}>What works right now</Title>
            <List size="sm" spacing="xs">
              <List.Item>Versioned API with one success envelope and one error envelope</List.Item>
              <List.Item>
                Request-id correlation from the browser through to the server logs
              </List.Item>
              <List.Item>Mantine theme with light, dark and system modes</List.Item>
              <List.Item>TanStack Query wired to a typed API client</List.Item>
              <List.Item>Redis cache and queues, MySQL, Horizon</List.Item>
            </List>
            <Text size="sm">
              See{' '}
              <Anchor component={Link} to="/system">
                system status
              </Anchor>{' '}
              for a live check.
            </Text>
          </Stack>
        </Card>
      </Stack>
    </Container>
  );
}
