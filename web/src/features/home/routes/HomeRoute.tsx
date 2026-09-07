import { Anchor, Button, Card, Container, Group, List, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router';

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
            Build a course, teach it, and mark the work — on a phone, in dark mode, with a keyboard.
          </Text>
        </Stack>

        <Group>
          <Button component={Link} to="/courses">
            Browse courses
          </Button>
          <Button component={Link} to="/dashboard" variant="default">
            My learning
          </Button>
        </Group>

        <Card>
          <Stack gap="xs">
            <Title order={3}>What works right now</Title>
            <List size="sm" spacing="xs">
              <List.Item>
                Accounts, email verification, instructor applications, and roles that can be scoped
                to a single course
              </List.Item>
              <List.Item>
                Course authoring: basics, a drag-and-drop curriculum, and a publish checklist that
                is enforced as well as displayed
              </List.Item>
              <List.Item>
                A player with video resume, notes, and progress that is stored rather than
                recomputed
              </List.Item>
              <List.Item>
                Quizzes across ten question types, timed by the server, with the answers never sent
                to an attempt in progress
              </List.Item>
              <List.Item>
                Assignments with file and written submissions, a late policy, feedback, and
                re-submission
              </List.Item>
              <List.Item>One grading queue covering quizzes and assignments together</List.Item>
            </List>
            <Text size="sm" c="dimmed">
              Enrolment payments, drip and certificates are still to come. See{' '}
              <Anchor component={Link} to="/system">
                system status
              </Anchor>{' '}
              for a live check of the API.
            </Text>
          </Stack>
        </Card>
      </Stack>
    </Container>
  );
}
