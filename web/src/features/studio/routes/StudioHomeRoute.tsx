import { Card, Container, List, Stack, Text, Title } from '@mantine/core';

import { useNavigate } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { EmptyState, PageHeader } from '@/shared/ui';

export function StudioHomeRoute() {
  const { session } = useSession();
  const navigate = useNavigate();

  return (
    <Container size="lg" py="lg">
      <PageHeader title="Studio" description="Where you build and publish courses." />

      <Stack gap="lg">
        <Card>
          <Stack gap="xs">
            <Title order={4}>Your courses</Title>
            <EmptyState
              title="Manage your courses"
              description="Create, edit and publish from the courses screen."
              action={{ label: 'Go to courses', onClick: () => navigate('/studio/courses') }}
            />
          </Stack>
        </Card>

        <Card>
          <Stack gap="xs">
            <Title order={4}>What you can do here</Title>
            <List size="sm" spacing={4}>
              {session?.permissions
                .filter(
                  (permission) =>
                    permission.startsWith('course.') || permission.startsWith('curriculum.'),
                )
                .map((permission) => (
                  <List.Item key={permission}>
                    <Text size="sm" ff="monospace">
                      {permission}
                    </Text>
                  </List.Item>
                ))}
            </List>
          </Stack>
        </Card>
      </Stack>
    </Container>
  );
}
