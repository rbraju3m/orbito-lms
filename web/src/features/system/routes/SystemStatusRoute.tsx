import { Badge, Card, Container, Group, SimpleGrid, Stack, Text } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';

import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { healthQuery } from '../api/queries';

/**
 * The Phase 2 exit check, rendered: it proves the SPA reaches the API through
 * CORS, unwraps the envelope, and shows real loading / error / success states.
 */
export function SystemStatusRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(healthQuery());

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="System status"
        description="Live health of the Orbito API and its dependencies."
      />

      {isPending ? <LoadingState rows={3} /> : null}

      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data ? (
        <Stack gap="md">
          <Group gap="sm">
            <Badge color={data.status === 'ok' ? 'success' : 'warning'} size="lg">
              {data.status === 'ok' ? 'All systems operational' : 'Degraded'}
            </Badge>
            <Text size="sm" c="dimmed">
              {data.app} · {data.environment} · v{data.version}
            </Text>
          </Group>

          <SimpleGrid cols={{ base: 1, sm: 3 }}>
            {Object.entries(data.checks).map(([name, check]) => (
              <Card key={name}>
                <Group justify="space-between">
                  <Text fw={600} tt="capitalize">
                    {name}
                  </Text>
                  <Badge color={check.ok ? 'success' : 'danger'} variant="light">
                    {check.ok ? 'up' : 'down'}
                  </Badge>
                </Group>
                {check.error ? (
                  <Text size="xs" c="dimmed" mt="xs">
                    {check.error}
                  </Text>
                ) : null}
              </Card>
            ))}
          </SimpleGrid>

          <Text size="xs" c="dimmed">
            Checked at {new Date(data.time).toLocaleString()}
          </Text>
        </Stack>
      ) : null}
    </Container>
  );
}
