import {
  Badge,
  Card,
  Container,
  Divider,
  Grid,
  Group,
  Image,
  List,
  Stack,
  Text,
  Title,
} from '@mantine/core';
import { IconCircleCheck, IconStarFilled, IconUsers } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';

import { ErrorState, LoadingState } from '@/shared/ui';

import { courseDetailQuery } from '../api/queries';

export function CourseDetailRoute() {
  const { slug = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(courseDetailQuery(slug));

  if (isPending) {
    return (
      <Container size="lg" py="lg">
        <LoadingState rows={4} height={80} />
      </Container>
    );
  }

  if (isError) {
    return (
      <Container size="lg" py="lg">
        <ErrorState error={error} onRetry={() => void refetch()} title="Course not found" />
      </Container>
    );
  }

  return (
    <Container size="lg" py="lg">
      <Grid gap="xl">
        <Grid.Col span={{ base: 12, md: 8 }}>
          <Stack gap="md">
            <Group gap="xs">
              {data.status !== 'published' ? (
                <Badge color="warning" variant="light">
                  {data.status_label}
                </Badge>
              ) : null}
              {data.category ? <Badge variant="light">{data.category.name}</Badge> : null}
              <Badge variant="outline" color="gray">
                {data.level_label}
              </Badge>
            </Group>

            <Title order={1}>{data.title}</Title>
            {data.subtitle ? (
              <Text size="lg" c="dimmed">
                {data.subtitle}
              </Text>
            ) : null}

            <Group gap="lg">
              {data.rating_count > 0 ? (
                <Group gap={4}>
                  <IconStarFilled size={15} color="var(--mantine-color-warning-6)" />
                  <Text size="sm">
                    {data.rating_avg.toFixed(1)} ({data.rating_count} reviews)
                  </Text>
                </Group>
              ) : null}
              <Group gap={4}>
                <IconUsers size={15} />
                <Text size="sm">{data.enrollment_count} enrolled</Text>
              </Group>
            </Group>

            {data.detail && data.detail.objectives.length > 0 ? (
              <Card>
                <Title order={3} mb="sm">
                  What you'll learn
                </Title>
                <List spacing="xs" icon={<IconCircleCheck size={16} />}>
                  {data.detail.objectives.map((objective) => (
                    <List.Item key={objective}>{objective}</List.Item>
                  ))}
                </List>
              </Card>
            ) : null}

            {data.description ? (
              <Stack gap="sm">
                <Title order={3}>About this course</Title>
                <Text className="orbito-prose" style={{ whiteSpace: 'pre-wrap' }}>
                  {data.description}
                </Text>
              </Stack>
            ) : null}

            {data.detail && data.detail.requirements.length > 0 ? (
              <Stack gap="sm">
                <Title order={3}>Requirements</Title>
                <List spacing={4}>
                  {data.detail.requirements.map((requirement) => (
                    <List.Item key={requirement}>{requirement}</List.Item>
                  ))}
                </List>
              </Stack>
            ) : null}
          </Stack>
        </Grid.Col>

        <Grid.Col span={{ base: 12, md: 4 }}>
          <Card padding={0}>
            {data.thumbnail ? (
              <Card.Section>
                <Image src={data.thumbnail} alt="" h={170} fit="cover" />
              </Card.Section>
            ) : null}

            <Stack gap="sm" p="md">
              <Text fw={700} size="xl">
                {data.pricing_model === 'free' ? 'Free' : 'Paid'}
              </Text>

              {/* The enrol endpoint exists, but the SPA has no enrolment
                  flow until Phase 9. Promising a button that does nothing
                  would be worse than saying so. */}
              <Text size="sm" c="dimmed">
                Enrolment opens in a later phase.
              </Text>

              <Divider />

              <Stack gap={4}>
                <Text size="sm" fw={600}>
                  Instructors
                </Text>
                {data.instructors?.map((instructor) => (
                  <Group key={instructor.id} gap="xs" justify="space-between">
                    <Text size="sm">{instructor.name}</Text>
                    <Badge size="xs" variant="light">
                      {instructor.role_label}
                    </Badge>
                  </Group>
                ))}
              </Stack>
            </Stack>
          </Card>
        </Grid.Col>
      </Grid>
    </Container>
  );
}
