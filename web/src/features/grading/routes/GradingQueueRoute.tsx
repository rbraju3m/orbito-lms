import {
  Badge,
  Button,
  Card,
  Container,
  Group,
  Pagination,
  SegmentedControl,
  Stack,
  Table,
  Text,
} from '@mantine/core';
import { IconChecklist, IconFileText } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useParams } from 'react-router';

import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { gradingQueueQuery } from '../api/queries';
import type { GradingQueueRow } from '../api/types';

/**
 * One list of work waiting for a person, across quizzes and assignments.
 *
 * An instructor thinks in terms of "what is there to mark today", not "which
 * kind of thing is it". The rows a particular grader may not open are filtered
 * out by the server, so nothing here 403s when clicked.
 */
export function GradingQueueRoute() {
  const { id: courseId = '' } = useParams();
  const [status, setStatus] = useState('awaiting_review');
  const [page, setPage] = useState(1);

  const { data, isPending, isError, error, refetch } = useQuery(
    gradingQueueQuery(courseId, status, page),
  );

  return (
    <Container size="lg" py="lg">
      <Stack gap="lg">
        <PageHeader
          title="Grading"
          description="Quizzes and assignments waiting to be marked, oldest first."
        />

        <SegmentedControl
          value={status}
          onChange={(value) => {
            setStatus(value);
            setPage(1);
          }}
          data={[
            { value: 'awaiting_review', label: 'Waiting' },
            { value: 'all', label: 'Everything' },
          ]}
          w="fit-content"
        />

        {isPending ? <LoadingState rows={4} height={52} /> : null}

        {isError ? (
          <ErrorState error={error} onRetry={() => void refetch()} title="Queue unavailable" />
        ) : null}

        {data && data.data.length === 0 ? (
          <EmptyState
            title={status === 'awaiting_review' ? 'Nothing to mark' : 'Nothing here yet'}
            description={
              status === 'awaiting_review'
                ? 'Everything handed in has been graded.'
                : 'Work appears here as learners hand it in.'
            }
          />
        ) : null}

        {data && data.data.length > 0 ? (
          <>
            <Card padding={0}>
              <Table.ScrollContainer minWidth={640}>
                <Table striped highlightOnHover>
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>Learner</Table.Th>
                      <Table.Th>Item</Table.Th>
                      <Table.Th>Handed in</Table.Th>
                      <Table.Th>Status</Table.Th>
                      <Table.Th />
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {data.data.map((row) => (
                      <QueueRow key={`${row.kind}-${row.id}`} row={row} />
                    ))}
                  </Table.Tbody>
                </Table>
              </Table.ScrollContainer>
            </Card>

            {data.meta.last_page > 1 ? (
              <Group justify="center">
                <Pagination
                  value={page}
                  onChange={setPage}
                  total={data.meta.last_page}
                  aria-label="Grading queue pages"
                />
              </Group>
            ) : null}
          </>
        ) : null}
      </Stack>
    </Container>
  );
}

function QueueRow({ row }: { row: GradingQueueRow }) {
  const href =
    row.kind === 'quiz' ? `/studio/grading/quiz/${row.id}` : `/studio/grading/assignment/${row.id}`;

  return (
    <Table.Tr>
      <Table.Td>{row.learner.name ?? 'Unknown'}</Table.Td>
      <Table.Td>
        <Group gap="xs" wrap="nowrap">
          {row.kind === 'quiz' ? <IconChecklist size={14} /> : <IconFileText size={14} />}
          <Text size="sm">{row.item.title ?? 'Removed item'}</Text>
        </Group>
      </Table.Td>
      <Table.Td>
        <Text size="sm" c="dimmed">
          {formatDateTime(row.submitted_at)}
        </Text>
      </Table.Td>
      <Table.Td>
        <Badge size="sm" variant="light" color={row.awaiting_review ? 'warning' : 'success'}>
          {row.awaiting_review ? 'Waiting' : 'Done'}
        </Badge>
      </Table.Td>
      <Table.Td>
        <Button size="compact-xs" variant="subtle" component={Link} to={href}>
          {row.awaiting_review ? 'Mark it' : 'Review'}
        </Button>
      </Table.Td>
    </Table.Tr>
  );
}
