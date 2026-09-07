import { Alert, Card, MultiSelect, Stack, Text, Title } from '@mantine/core';
import { IconAlertTriangle, IconInfoCircle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';

import type { CoursePrerequisiteSummary } from '@/features/catalog/api/types';
import { useSetPrerequisites } from '@/features/enrollment/api/queries';
import { ApiError } from '@/shared/api/errors';

import { studioCoursesQuery } from '../api/queries';

/**
 * Prerequisites gate ENROLMENT, not ongoing access.
 *
 * Saved as a whole set, never a delta — two authors editing the same course
 * must not interleave into a set neither of them asked for. The server refuses
 * self-references and cycles, and the message says which.
 */
export function PrerequisitesCard({
  courseId,
  courseRef,
  prerequisites,
}: {
  courseId: string;
  courseRef: number;
  prerequisites: CoursePrerequisiteSummary[];
}) {
  const setPrerequisites = useSetPrerequisites(courseId);

  // The author's own courses are the realistic candidates; a full catalogue
  // picker would list every course in the academy.
  const candidates = useQuery(studioCoursesQuery({ page: 1 }));

  const options = (candidates.data?.data ?? [])
    .filter((course) => course.ref !== courseRef)
    .map((course) => ({ value: String(course.ref), label: course.title }));

  const value = prerequisites.map((prerequisite) => String(prerequisite.ref));
  const error = setPrerequisites.error instanceof ApiError ? setPrerequisites.error : null;

  return (
    <Card withBorder>
      <Stack gap="md">
        <Title order={4}>Prerequisites</Title>

        <Alert color="gray" variant="light" icon={<IconInfoCircle size={16} />}>
          <Text size="sm">
            Learners must have completed these before they can enrol. Adding one never removes
            anybody already enrolled.
          </Text>
        </Alert>

        <MultiSelect
          label="Must be completed first"
          placeholder={options.length === 0 ? 'No other courses yet' : 'Choose courses'}
          data={options}
          value={value}
          searchable
          clearable
          disabled={candidates.isPending || setPrerequisites.isPending}
          onChange={(next) => setPrerequisites.mutate(next.map(Number))}
          maxValues={10}
        />

        {error && (
          <Alert color="red" icon={<IconAlertTriangle size={16} />}>
            {error.message}
          </Alert>
        )}
      </Stack>
    </Card>
  );
}
