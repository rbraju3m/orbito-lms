import {
  Button,
  Card,
  Group,
  Pagination,
  Select,
  Stack,
  Table,
  Text,
  TextInput,
} from '@mantine/core';
import { IconSearch, IconUserPlus, IconUsers } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import { rosterQuery, useChangeEnrollment } from '../api/queries';
import type { CourseStudent, EnrollmentAction, RosterFilters } from '../api/types';
import { EnrollmentActionModal } from './EnrollmentActionModal';
import { EnrolStudentsModal } from './EnrolStudentsModal';
import { StudentRow } from './StudentRow';

const STATUS_OPTIONS = [
  { value: 'all', label: 'All statuses' },
  { value: 'active', label: 'In progress' },
  { value: 'completed', label: 'Completed' },
  { value: 'expired', label: 'Expired' },
  { value: 'suspended', label: 'Suspended' },
  { value: 'cancelled', label: 'Cancelled' },
];

export function StudentsPanel({ courseId }: { courseId: string }) {
  const [filters, setFilters] = useState<RosterFilters>({
    status: 'all',
    search: '',
    page: 1,
  });
  const [enrolOpen, setEnrolOpen] = useState(false);
  const [target, setTarget] = useState<CourseStudent | null>(null);
  const [action, setAction] = useState<EnrollmentAction | null>(null);

  const roster = useQuery(rosterQuery(courseId, filters));
  const change = useChangeEnrollment(courseId);

  // Any filter change returns to page 1: staying on page 4 of a narrower
  // result set shows an empty table that looks like "no students".
  const setFilter = (patch: Partial<RosterFilters>) =>
    setFilters((current) => ({ ...current, ...patch, page: patch.page ?? 1 }));

  const closeAction = () => {
    setTarget(null);
    setAction(null);
    change.reset();
  };

  const rows = roster.data?.data ?? [];
  const meta = roster.data?.meta;
  const filtered = filters.status !== 'all' || filters.search !== '';

  return (
    <Stack gap="md">
      <Group justify="space-between" wrap="wrap">
        <Group gap="sm">
          <TextInput
            aria-label="Search students"
            leftSection={<IconSearch size={16} />}
            placeholder="Name or email"
            value={filters.search}
            onChange={(event) => setFilter({ search: event.currentTarget.value })}
            w={240}
          />
          <Select
            aria-label="Filter by status"
            data={STATUS_OPTIONS}
            value={filters.status}
            onChange={(value) => setFilter({ status: value ?? 'all' })}
            w={180}
            allowDeselect={false}
          />
        </Group>

        <Button leftSection={<IconUserPlus size={16} />} onClick={() => setEnrolOpen(true)}>
          Enrol students
        </Button>
      </Group>

      <Card withBorder padding={0}>
        {roster.isPending && <LoadingState rows={4} label="Loading students" />}

        {roster.isError && (
          <ErrorState error={roster.error} onRetry={() => void roster.refetch()} />
        )}

        {roster.isSuccess && rows.length === 0 && (
          <EmptyState
            icon={IconUsers}
            title={filtered ? 'No students match' : 'No students yet'}
            description={
              filtered
                ? 'Try a different search or status.'
                : 'Enrol someone by email, or share the course once it is published.'
            }
            action={
              filtered
                ? {
                    label: 'Clear filters',
                    onClick: () => setFilter({ status: 'all', search: '' }),
                  }
                : { label: 'Enrol students', onClick: () => setEnrolOpen(true) }
            }
          />
        )}

        {roster.isSuccess && rows.length > 0 && (
          <Table.ScrollContainer minWidth={720}>
            <Table highlightOnHover verticalSpacing="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Student</Table.Th>
                  <Table.Th>Status</Table.Th>
                  <Table.Th>Progress</Table.Th>
                  <Table.Th>Enrolled</Table.Th>
                  <Table.Th>Last active</Table.Th>
                  <Table.Th />
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {rows.map((student) => (
                  <StudentRow
                    key={student.enrollment.id}
                    student={student}
                    busy={change.isPending}
                    onAction={(next, row) => {
                      setTarget(row);
                      setAction(next);
                    }}
                  />
                ))}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Card>

      {meta && meta.last_page > 1 && (
        <Group justify="space-between">
          <Text c="dimmed" size="sm">
            {meta.total} student{meta.total === 1 ? '' : 's'}
          </Text>
          <Pagination
            total={meta.last_page}
            value={meta.current_page}
            onChange={(page) => setFilter({ page })}
          />
        </Group>
      )}

      <EnrolStudentsModal
        courseId={courseId}
        opened={enrolOpen}
        onClose={() => setEnrolOpen(false)}
      />

      <EnrollmentActionModal
        student={target}
        action={action}
        pending={change.isPending}
        error={change.error}
        onClose={closeAction}
        onConfirm={(input) => {
          if (!target || !action) return;

          change.mutate(
            { enrollmentId: target.enrollment.id, action, ...input },
            { onSuccess: closeAction },
          );
        }}
      />
    </Stack>
  );
}
