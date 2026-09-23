import { Badge, Card, Group, Image, Stack, Text, Title } from '@mantine/core';
import { IconStarFilled, IconUsers } from '@tabler/icons-react';
import { Link } from 'react-router';

import { t } from '@/shared/i18n';
import { formatNumber } from '@/shared/lib/number';

import type { CourseListItem } from '../api/types';

export interface CourseCardProps {
  course: CourseListItem;
  /** Studio cards link into the editor, catalogue cards to the public page. */
  to?: string;
  /**
   * The title's level in the page outline — one below the heading above the
   * grid. It is drawn at h4 size whatever it is.
   */
  headingOrder?: 2 | 3;
}

export function CourseCard({ course, to, headingOrder = 2 }: CourseCardProps) {
  return (
    <Card component={Link} to={to ?? `/courses/${course.slug}`} padding="0" withBorder>
      <Card.Section>
        {course.thumbnail_url ? (
          <Image src={course.thumbnail_url} alt="" h={150} fit="cover" />
        ) : (
          <div
            aria-hidden
            style={{
              height: 150,
              background:
                'linear-gradient(135deg, var(--mantine-color-orbito-5), var(--mantine-color-orbito-8))',
            }}
          />
        )}
      </Card.Section>

      <Stack gap="xs" p="md">
        <Group gap="xs">
          {course.status !== 'published' ? (
            <Badge size="sm" variant="light" color={course.status === 'draft' ? 'gray' : 'warning'}>
              {course.status_label}
            </Badge>
          ) : null}
          {course.category ? (
            <Badge size="sm" variant="light">
              {course.category.name}
            </Badge>
          ) : null}
          <Badge size="sm" variant="outline" color="gray">
            {course.level_label}
          </Badge>
        </Group>

        <Title order={headingOrder} size="h4" lineClamp={2}>
          {course.title}
        </Title>

        {course.subtitle ? (
          <Text size="sm" c="dimmed" lineClamp={2}>
            {course.subtitle}
          </Text>
        ) : null}

        <Group gap="lg" mt="auto">
          {course.rating_count > 0 ? (
            <Group gap={4}>
              <IconStarFilled size={13} color="var(--mantine-color-warning-6)" />
              <Text size="xs" c="dimmed">
                {formatNumber(course.rating_avg, {
                  minimumFractionDigits: 1,
                  maximumFractionDigits: 1,
                })}{' '}
                ({formatNumber(course.rating_count)})
              </Text>
            </Group>
          ) : null}

          <Group gap={4}>
            <IconUsers size={13} />
            <Text size="xs" c="dimmed">
              {formatNumber(course.enrollment_count)}
            </Text>
          </Group>

          {course.pricing_model === 'free' ? (
            <Text size="xs" fw={600} c="success">
              {t('catalog.card.free', 'Free')}
            </Text>
          ) : null}
        </Group>
      </Stack>
    </Card>
  );
}
