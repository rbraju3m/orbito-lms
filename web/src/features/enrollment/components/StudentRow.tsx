import { ActionIcon, Group, Menu, Progress, Stack, Table, Text } from '@mantine/core';
import {
  IconDots,
  IconPlayerPause,
  IconPlayerPlay,
  IconCalendarPlus,
  IconTrash,
} from '@tabler/icons-react';

import { formatDate } from '@/shared/lib/datetime';

import type { CourseStudent, EnrollmentAction } from '../api/types';
import { EnrollmentStatusBadge } from './EnrollmentStatusBadge';

interface StudentRowProps {
  student: CourseStudent;
  onAction: (action: EnrollmentAction, student: CourseStudent) => void;
  busy: boolean;
}

export function StudentRow({ student, onAction, busy }: StudentRowProps) {
  const { enrollment, progress } = student;
  const suspended = enrollment.status === 'suspended';

  return (
    <Table.Tr>
      <Table.Td>
        <Stack gap={0}>
          <Text fw={500} size="sm">
            {student.student.name ?? 'Unknown'}
          </Text>
          <Text c="dimmed" size="xs">
            {student.student.email ?? '—'}
          </Text>
        </Stack>
      </Table.Td>

      <Table.Td>
        <EnrollmentStatusBadge enrollment={enrollment} />
      </Table.Td>

      <Table.Td miw={140}>
        {/*
          Absent progress is not zero progress. A learner with no recorded
          items has not started; rendering 0% would claim we know they tried.
        */}
        {progress ? (
          <Stack gap={4}>
            <Progress value={progress.percent} size="sm" radius="xl" />
            <Text c="dimmed" size="xs">
              {progress.completed_items}/{progress.total_items} · {Math.round(progress.percent)}%
            </Text>
          </Stack>
        ) : (
          <Text c="dimmed" size="xs">
            Not started
          </Text>
        )}
      </Table.Td>

      <Table.Td>
        <Text size="sm">{formatDate(enrollment.enrolled_at)}</Text>
      </Table.Td>

      <Table.Td>
        <Text c="dimmed" size="sm">
          {formatDate(progress?.last_activity_at)}
        </Text>
      </Table.Td>

      <Table.Td w={56}>
        <Group justify="flex-end">
          <Menu position="bottom-end" withinPortal>
            <Menu.Target>
              <ActionIcon
                aria-label={`Actions for ${student.student.name ?? student.student.email ?? 'student'}`}
                variant="subtle"
                color="gray"
                disabled={busy}
              >
                <IconDots size={16} />
              </ActionIcon>
            </Menu.Target>

            <Menu.Dropdown>
              {suspended ? (
                <Menu.Item
                  leftSection={<IconPlayerPlay size={14} />}
                  onClick={() => onAction('reinstate', student)}
                >
                  Reinstate
                </Menu.Item>
              ) : (
                <Menu.Item
                  leftSection={<IconPlayerPause size={14} />}
                  onClick={() => onAction('suspend', student)}
                >
                  Suspend
                </Menu.Item>
              )}

              <Menu.Item
                leftSection={<IconCalendarPlus size={14} />}
                onClick={() => onAction('extend', student)}
              >
                Change access period
              </Menu.Item>

              <Menu.Divider />

              <Menu.Item
                color="red"
                leftSection={<IconTrash size={14} />}
                onClick={() => onAction('revoke', student)}
              >
                Revoke access
              </Menu.Item>
            </Menu.Dropdown>
          </Menu>
        </Group>
      </Table.Td>
    </Table.Tr>
  );
}
