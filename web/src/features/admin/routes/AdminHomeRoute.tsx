import { Card, Container, SimpleGrid, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { PageHeader } from '@/shared/ui';

const AREAS = [
  {
    to: '/admin/instructors',
    title: 'Instructor applications',
    description: 'Approve, reject or block instructors.',
    permission: 'instructor.view',
  },
  {
    to: '/admin/academy',
    title: 'Academy',
    description: 'Who may sign up, and the link that lets them.',
    permission: 'settings.view',
  },
];

export function AdminHomeRoute() {
  const { can } = useSession();

  return (
    <Container size="lg" py="lg">
      <PageHeader title="Administration" description="Run the platform." />

      <SimpleGrid cols={{ base: 1, sm: 2 }}>
        {AREAS.filter((area) => can(area.permission)).map((area) => (
          <Card key={area.to} component={Link} to={area.to}>
            <Stack gap={4}>
              <Title order={4}>{area.title}</Title>
              <Text size="sm" c="dimmed">
                {area.description}
              </Text>
            </Stack>
          </Card>
        ))}
      </SimpleGrid>
    </Container>
  );
}
