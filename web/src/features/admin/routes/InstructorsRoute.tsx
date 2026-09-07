import { Badge, Button, Card, Container, Group, Stack, Table, Text } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { instructorsQuery, useReviewInstructor } from '../api/queries';

const STATUS_COLOR: Record<string, string> = {
  pending: 'warning',
  approved: 'success',
  rejected: 'danger',
  blocked: 'danger',
};

export function InstructorsRoute() {
  const [status, setStatus] = useState<string | undefined>('pending');
  const { data, isPending, isError, error, refetch } = useQuery(instructorsQuery(status));
  const { mutateAsync, isPending: isReviewing } = useReviewInstructor();

  return (
    <Container size="lg" py="lg">
      <PageHeader
        title="Instructor applications"
        description="Approving an application is what grants the instructor role."
        actions={
          <Group gap="xs">
            {['pending', 'approved', undefined].map((value) => (
              <Button
                key={value ?? 'all'}
                size="xs"
                variant={status === value ? 'filled' : 'light'}
                onClick={() => setStatus(value)}
              >
                {value ?? 'All'}
              </Button>
            ))}
          </Group>
        }
      />

      {isPending ? <LoadingState rows={3} /> : null}
      {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          title="Nothing to review"
          description="No applications match this filter — you're all caught up."
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <Card padding={0}>
          <Table.ScrollContainer minWidth={640}>
            <Table striped highlightOnHover>
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Applicant</Table.Th>
                  <Table.Th>Status</Table.Th>
                  <Table.Th>Applied</Table.Th>
                  <Table.Th />
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {data.data.map((row) => (
                  <Table.Tr key={row.id}>
                    <Table.Td>
                      <Stack gap={0}>
                        <Text size="sm" fw={500}>
                          {row.user?.name ?? 'Unknown'}
                        </Text>
                        <Text size="xs" c="dimmed">
                          {row.user?.email}
                        </Text>
                      </Stack>
                    </Table.Td>
                    <Table.Td>
                      <Badge color={STATUS_COLOR[row.status] ?? 'gray'} variant="light">
                        {row.status_label}
                      </Badge>
                    </Table.Td>
                    <Table.Td>
                      <Text size="sm" c="dimmed">
                        {row.applied_at ? new Date(row.applied_at).toLocaleDateString() : '—'}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      {row.status === 'pending' ? (
                        <Group gap="xs" justify="flex-end">
                          <Button
                            size="xs"
                            loading={isReviewing}
                            onClick={() => {
                              void mutateAsync({ id: row.id, decision: 'approved' });
                            }}
                          >
                            Approve
                          </Button>
                          <Button
                            size="xs"
                            variant="light"
                            color="danger"
                            loading={isReviewing}
                            onClick={() => {
                              void mutateAsync({ id: row.id, decision: 'rejected' });
                            }}
                          >
                            Reject
                          </Button>
                        </Group>
                      ) : null}
                    </Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        </Card>
      ) : null}
    </Container>
  );
}
