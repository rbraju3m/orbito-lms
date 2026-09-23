import { ActionIcon, Container, Group, Stack, Text, Title } from '@mantine/core';
import { IconArrowLeft } from '@tabler/icons-react';
import { Link, useParams } from 'react-router';

import { t } from '@/shared/i18n';

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
            aria-label={t('engagement.back_to_course', 'Back to the course')}
          >
            <IconArrowLeft size={18} />
          </ActionIcon>
          <Text size="sm" c="dimmed">
            {t('engagement.back_to_course', 'Back to the course')}
          </Text>
        </Group>

        {/* The page's only heading, so an h1 — drawn at the size it always was. */}
        <Title order={1} size="h2">
          {t('engagement.announcements.title', 'Announcements')}
        </Title>

        <AnnouncementList courseId={courseId} />
      </Stack>
    </Container>
  );
}
