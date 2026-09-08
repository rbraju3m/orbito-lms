import { ActionIcon, Container, Group, Stack, Text, Title } from '@mantine/core';
import { IconArrowLeft } from '@tabler/icons-react';
import { Link, useParams } from 'react-router';

import { AnnouncementList } from '../components/AnnouncementList';

/**
 * Every announcement in a course, on its own page.
 *
 * This is what the announcement notification links to — `/learn/{course}/
 * announcements` is the `action_path` the server freezes into the payload, so
 * this route is part of that contract rather than a convenience.
 */
export function CourseAnnouncementsRoute() {
  const { courseId = '' } = useParams();

  return (
    <Container size="md" py="lg">
      <Stack gap="lg">
        <Group gap="xs">
          <ActionIcon
            component={Link}
            to={`/learn/${courseId}`}
            variant="subtle"
            aria-label="Back to the course"
          >
            <IconArrowLeft size={18} />
          </ActionIcon>
          <Text size="sm" c="dimmed">
            Back to the course
          </Text>
        </Group>

        <Title order={2}>Announcements</Title>

        <AnnouncementList courseId={courseId} />
      </Stack>
    </Container>
  );
}
