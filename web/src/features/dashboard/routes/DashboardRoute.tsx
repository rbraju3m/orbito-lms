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
import { plural, t } from '@/shared/i18n';
import { PageHeader } from '@/shared/ui';

import { ContinueLearning } from '../components/ContinueLearning';

export function DashboardRoute() {
  const { session, can } = useSession();
  const [resent, setResent] = useState(false);

  if (!session) return null;

  return (
    <Container size="lg" py="lg">
      <PageHeader
        title={t('dashboard.welcome', 'Welcome, {name}', {
          name: session.user.name.split(' ')[0] ?? '',
        })}
        description={t('dashboard.description', 'Your learning and teaching, in one place.')}
      />

      <Stack gap="lg">
        {session.must_verify_email ? (
          <Alert
            color="warning"
            icon={<IconMailExclamation size={16} />}
            title={t('dashboard.verify.title', 'Verify your email address')}
          >
            <Stack gap="sm" align="flex-start">
              <Text size="sm">
                {t(
                  'dashboard.verify.body',
                  'We sent a link to {email}. Verifying keeps your account recoverable.',
                  { email: session.user.email ?? '' },
                )}
              </Text>
              <Button
                size="xs"
                variant="light"
                disabled={resent}
                onClick={() => {
                  void resendVerification().then(() => setResent(true));
                }}
              >
                {resent
                  ? t('dashboard.verify.sent', 'Link sent')
                  : t('dashboard.verify.resend', 'Resend link')}
              </Button>
            </Stack>
          </Alert>
        ) : null}

        <Card>
          <Stack gap="sm">
            <Group justify="space-between">
              <Title order={2} size="h3">
                {t('dashboard.access.title', 'Your access')}
              </Title>
              <Badge variant="light">
                {plural('dashboard.access.permissions', session.permissions.length, {
                  one: '{count} permission',
                  other: '{count} permissions',
                })}
              </Badge>
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
                ? t(
                    'dashboard.access.can_create',
                    'You can create and publish courses from Studio.',
                  )
                : session.is_instructor
                  ? t('dashboard.access.instructor', 'Your instructor account is active.')
                  : t(
                      'dashboard.access.apply',
                      'Apply to teach from your profile to start creating courses.',
                    )}
            </Text>
          </Stack>
        </Card>

        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          <Card>
            <Stack gap="xs">
              <Title order={2} size="h4">
                {t('dashboard.continue.title', 'Continue learning')}
              </Title>
              <ContinueLearning />
            </Stack>
          </Card>

          <Card>
            <Stack gap="xs">
              <Title order={2} size="h4">
                {t('dashboard.account.title', 'Account')}
              </Title>
              <Text size="sm" c="dimmed">
                {t('dashboard.account.body', 'Update your details, or apply to teach.')}
              </Text>
              <Group gap="xs">
                <Button component={Link} to="/account/profile" variant="light" size="xs">
                  {t('dashboard.account.edit_profile', 'Edit profile')}
                </Button>
                <Button component={Link} to="/account/security" variant="subtle" size="xs">
                  {t('dashboard.account.security', 'Security')}
                </Button>
              </Group>
            </Stack>
          </Card>
        </SimpleGrid>
      </Stack>
    </Container>
  );
}
