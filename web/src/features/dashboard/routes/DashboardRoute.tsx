import {
  Alert,
  Badge,
  Button,
  Card,
  Container,
  Group,
  SimpleGrid,
  Stack,
  Text,
  Title,
} from '@mantine/core';
import { IconMailExclamation } from '@tabler/icons-react';
import { useState } from 'react';
import { Link } from 'react-router';

import { resendVerification } from '@/features/auth/api/requests';
import { useSession } from '@/features/auth/hooks/useSession';
import { EmptyState, PageHeader } from '@/shared/ui';

export function DashboardRoute() {
  const { session, can } = useSession();
  const [resent, setResent] = useState(false);

  if (!session) return null;

  return (
    <Container size="lg" py="lg">
      <PageHeader
        title={`Welcome, ${session.user.name.split(' ')[0]}`}
        description="Your learning and teaching, in one place."
      />

      <Stack gap="lg">
        {session.must_verify_email ? (
          <Alert
            color="warning"
            icon={<IconMailExclamation size={16} />}
            title="Verify your email address"
          >
            <Stack gap="sm" align="flex-start">
              <Text size="sm">
                We sent a link to {session.user.email}. Verifying keeps your account recoverable.
              </Text>
              <Button
                size="xs"
                variant="light"
                disabled={resent}
                onClick={() => {
                  void resendVerification().then(() => setResent(true));
                }}
              >
                {resent ? 'Link sent' : 'Resend link'}
              </Button>
            </Stack>
          </Alert>
        ) : null}

        <Card>
          <Stack gap="sm">
            <Group justify="space-between">
              <Title order={3}>Your access</Title>
              <Badge variant="light">{session.permissions.length} permissions</Badge>
            </Group>

            <Group gap="xs">
              {session.roles.map((role) => (
                <Badge key={role} variant="filled">
                  {role.replace(/_/g, ' ')}
                </Badge>
              ))}
            </Group>

            <Text size="sm" c="dimmed">
              {can('course.create')
                ? 'You can create and publish courses from Studio.'
                : session.is_instructor
                  ? 'Your instructor account is active.'
                  : 'Apply to teach from your profile to start creating courses.'}
            </Text>
          </Stack>
        </Card>

        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          <Card>
            <Stack gap="xs">
              <Title order={4}>Continue learning</Title>
              <EmptyState
                title="No courses yet"
                description="Courses arrive in Phase 4. Enrolment and the player follow in Phase 6."
              />
            </Stack>
          </Card>

          <Card>
            <Stack gap="xs">
              <Title order={4}>Account</Title>
              <Text size="sm" c="dimmed">
                Update your details, or apply to teach.
              </Text>
              <Group gap="xs">
                <Button component={Link} to="/account/profile" variant="light" size="xs">
                  Edit profile
                </Button>
                <Button component={Link} to="/account/security" variant="subtle" size="xs">
                  Security
                </Button>
              </Group>
            </Stack>
          </Card>
        </SimpleGrid>
      </Stack>
    </Container>
  );
}
