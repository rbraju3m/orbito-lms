import { Anchor, Button, Card, Container, Group, List, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router';

import { rich, t } from '@/shared/i18n';

/**
 * The public landing page while the marketing site (P16) does not exist.
 *
 * It describes what the product actually does today rather than what it is
 * planned to do — a home page that promises a feature the user cannot reach is
 * worse than one that says less.
 */
export function HomeRoute() {
  return (
    <Container size="md" py="xl">
      <Stack gap="lg">
        <Stack gap="xs">
          <Title order={1}>Orbito LMS</Title>
          <Text c="dimmed">
            {t(
              'home.tagline',
              'Build a course, teach it, and mark the work — on a phone, in dark mode, with a keyboard.',
            )}
          </Text>
        </Stack>

        <Group>
          <Button component={Link} to="/courses">
            {t('home.browse', 'Browse courses')}
          </Button>
          <Button component={Link} to="/dashboard" variant="default">
            {t('home.my_learning', 'My learning')}
          </Button>
        </Group>

        <Card>
          <Stack gap="xs">
            <Title order={3}>{t('home.works.title', 'What works right now')}</Title>
            <List size="sm" spacing="xs">
              <List.Item>
                {t(
                  'home.works.accounts',
                  'Accounts, email verification, instructor applications, and roles that can be scoped to a single course',
                )}
              </List.Item>
              <List.Item>
                {t(
                  'home.works.authoring',
                  'Course authoring: basics, a drag-and-drop curriculum, and a publish checklist that is enforced as well as displayed',
                )}
              </List.Item>
              <List.Item>
                {t(
                  'home.works.player',
                  'A player with video resume, notes, and progress that is stored rather than recomputed',
                )}
              </List.Item>
              <List.Item>
                {t(
                  'home.works.quizzes',
                  'Quizzes across ten question types, timed by the server, with the answers never sent to an attempt in progress',
                )}
              </List.Item>
              <List.Item>
                {t(
                  'home.works.assignments',
                  'Assignments with file and written submissions, a late policy, feedback, and re-submission',
                )}
              </List.Item>
              <List.Item>
                {t(
                  'home.works.grading',
                  'One grading queue covering quizzes and assignments together',
                )}
              </List.Item>
            </List>
            <Text size="sm" c="dimmed">
              {rich(
                'home.status',
                'Enrolment payments, drip and certificates are still to come. See <link>system status</link> for a live check of the API.',
                {
                  link: (chunk) => (
                    <Anchor component={Link} to="/system">
                      {chunk}
                    </Anchor>
                  ),
                },
              )}
            </Text>
          </Stack>
        </Card>
      </Stack>
    </Container>
  );
}
