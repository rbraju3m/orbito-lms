import { Alert, Badge, Card, Group, List, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import { IconCircleCheck, IconCircleDashed, IconInfoCircle } from '@tabler/icons-react';

import type { PublishCheck } from '@/features/catalog/api/types';

export interface PublishChecklistCardProps {
  checks: PublishCheck[];
}

/**
 * Rendered from the server's own rules (`publish_checklist` on the course
 * resource), so what the instructor sees can never disagree with what publish
 * will accept.
 */
export function PublishChecklistCard({ checks }: PublishChecklistCardProps) {
  const blocking = checks.filter((check) => check.blocking);
  const advisory = checks.filter((check) => !check.blocking);
  const outstanding = blocking.filter((check) => !check.passed);

  return (
    <Card>
      <Stack gap="md">
        <Group justify="space-between">
          <Title order={4}>Ready to publish?</Title>
          <Badge color={outstanding.length === 0 ? 'success' : 'warning'} variant="light">
            {outstanding.length === 0 ? 'Ready' : `${outstanding.length} to fix`}
          </Badge>
        </Group>

        <List spacing={6} size="sm" center>
          {blocking.map((check) => (
            <List.Item
              key={check.code}
              icon={
                <ThemeIcon
                  size={20}
                  radius="xl"
                  color={check.passed ? 'success' : 'warning'}
                  variant="light"
                >
                  {check.passed ? <IconCircleCheck size={13} /> : <IconCircleDashed size={13} />}
                </ThemeIcon>
              }
            >
              <Text size="sm" c={check.passed ? 'dimmed' : undefined}>
                {check.message}
              </Text>
            </List.Item>
          ))}
        </List>

        {advisory.some((check) => !check.passed) ? (
          <Alert
            color="info"
            variant="light"
            icon={<IconInfoCircle size={16} />}
            title="Recommended"
          >
            <List spacing={4} size="sm">
              {advisory
                .filter((check) => !check.passed)
                .map((check) => (
                  <List.Item key={check.code}>{check.message}</List.Item>
                ))}
            </List>
          </Alert>
        ) : null}
      </Stack>
    </Card>
  );
}
