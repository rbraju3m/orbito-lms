import { Alert, Card, NumberInput, Select, Stack, Switch, Text, Title } from '@mantine/core';
import { IconInfoCircle } from '@tabler/icons-react';

import type { CourseSettings, DripMode } from '@/features/catalog/api/types';
import { optionalNumberValue } from '@/shared/lib/numberValue';

import { useUpdateCourseSettings } from '../api/queries';

const DRIP_OPTIONS: { value: DripMode; label: string }[] = [
  { value: 'none', label: 'No drip — everything available immediately' },
  { value: 'by_date', label: 'On a fixed date' },
  { value: 'by_days', label: 'A number of days after enrolling' },
  { value: 'sequential', label: 'After the previous item is completed' },
];

/**
 * The course-level half of access. The per-item half lives in the curriculum
 * builder, and only shows the field the mode chosen here actually reads.
 *
 * Saved per field rather than behind a Save button: these are independent
 * toggles, and batching them would mean an author who flips one and navigates
 * away loses it silently.
 */
export function AccessSettingsCard({
  courseId,
  settings,
}: {
  courseId: string;
  settings: CourseSettings;
}) {
  const update = useUpdateCourseSettings(courseId);
  const save = (patch: Partial<CourseSettings>) => update.mutate(patch);

  return (
    <Card withBorder>
      <Stack gap="md">
        <Title order={4}>Access</Title>

        <Select
          label="Release schedule"
          description="Per-item dates are set in the curriculum."
          data={DRIP_OPTIONS}
          value={settings.drip_mode}
          onChange={(value) => value && save({ drip_mode: value as DripMode })}
          allowDeselect={false}
        />

        {settings.drip_mode !== 'none' && (
          <Alert color="gray" variant="light" icon={<IconInfoCircle size={16} />}>
            <Text size="sm">
              Locked items still appear in the curriculum with the date they unlock — learners can
              see what they are working towards.
            </Text>
          </Alert>
        )}

        <NumberInput
          label="Student limit"
          description="Leave empty for no limit. Staff enrolments count towards it too."
          min={1}
          max={1000000}
          value={settings.max_students ?? ''}
          onChange={(value) => save({ max_students: optionalNumberValue(value) })}
        />

        <NumberInput
          label="Access expires after (days)"
          description="Counted from when each learner's access starts. Empty means lifetime access."
          min={1}
          max={3650}
          value={settings.enrollment_expires_days ?? ''}
          onChange={(value) => save({ enrollment_expires_days: optionalNumberValue(value) })}
        />

        <Switch
          checked={settings.reset_progress_allowed}
          onChange={(event) => save({ reset_progress_allowed: event.currentTarget.checked })}
          label="Learners can reset their progress"
          description="Clears what they marked done. Passed quizzes and graded work are kept."
        />

        <Switch
          checked={settings.retake_allowed}
          onChange={(event) => save({ retake_allowed: event.currentTarget.checked })}
          label="Learners can retake the course"
          description="Available once they have finished it."
        />
      </Stack>
    </Card>
  );
}
