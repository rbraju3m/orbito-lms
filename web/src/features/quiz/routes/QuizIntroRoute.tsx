import {
  Alert,
  Badge,
  Button,
  Card,
  Container,
  Group,
  Stack,
  Table,
  Text,
  Title,
} from '@mantine/core';
import { IconAlertCircle, IconClock } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { ErrorState, LoadingState } from '@/shared/ui';

import { attemptHistoryQuery, useStartAttempt } from '../api/queries';

/** The screen before the runner: what you're about to take, and past attempts. */
export function QuizIntroRoute() {
  const { courseId = '', itemId = '' } = useParams();
  const navigate = useNavigate();
  const { data, isPending, isError, error, refetch } = useQuery(attemptHistoryQuery(itemId));
  const start = useStartAttempt(itemId);
  const [startError, setStartError] = useState<string | null>(null);

  if (isPending) {
    return (
      <Container size="md" py="lg">
        <LoadingState rows={3} height={80} />
      </Container>
    );
  }

  if (isError) {
    return (
      <Container size="md" py="lg">
        <ErrorState error={error} onRetry={() => void refetch()} title="Quiz unavailable" />
      </Container>
    );
  }

  const exhausted = data.attempts_allowed !== null && data.attempts_used >= data.attempts_allowed;
  const open = data.attempts.find((attempt) => attempt.status === 'in_progress');

  return (
    <Container size="md" py="lg">
      <Stack gap="lg">
        <Stack gap="xs">
          <Title order={2}>Quiz</Title>
          <Group gap="xs">
            <Badge variant="light">
              {data.attempts_allowed === null
                ? 'Unlimited attempts'
                : `${data.attempts_used} of ${data.attempts_allowed} attempts used`}
            </Badge>
          </Group>
        </Stack>

        {startError ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {startError}
          </Alert>
        ) : null}

        {open ? (
          <Alert color="info" icon={<IconClock size={16} />} title="You have an attempt open">
            <Stack gap="sm" align="flex-start">
              <Text size="sm">Pick up where you left off.</Text>
              <Button
                size="xs"
                component={Link}
                to={`/learn/${courseId}/${itemId}/quiz/${open.id}`}
              >
                Resume
              </Button>
            </Stack>
          </Alert>
        ) : null}

        {data.attempts.length > 0 ? (
          <Card padding={0}>
            <Table.ScrollContainer minWidth={420}>
              <Table striped>
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Attempt</Table.Th>
                    <Table.Th>Status</Table.Th>
                    <Table.Th>Score</Table.Th>
                    <Table.Th />
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {data.attempts.map((attempt) => (
                    <Table.Tr key={attempt.id}>
                      <Table.Td>#{attempt.attempt_number}</Table.Td>
                      <Table.Td>
                        <Badge
                          size="sm"
                          variant="light"
                          color={
                            attempt.status === 'graded'
                              ? attempt.passed
                                ? 'success'
                                : 'danger'
                              : 'gray'
                          }
                        >
                          {attempt.status_label}
                        </Badge>
                      </Table.Td>
                      <Table.Td>
                        {attempt.percent === undefined ? '—' : `${Math.round(attempt.percent)}%`}
                      </Table.Td>
                      <Table.Td>
                        {attempt.status !== 'in_progress' ? (
                          <Button
                            size="compact-xs"
                            variant="subtle"
                            component={Link}
                            to={`/learn/${courseId}/${itemId}/quiz/${attempt.id}/result`}
                          >
                            Review
                          </Button>
                        ) : null}
                      </Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </Table.ScrollContainer>
          </Card>
        ) : null}

        {!open ? (
          <Group>
            <Button
              disabled={exhausted}
              loading={start.isPending}
              onClick={() => {
                setStartError(null);
                start.mutate(undefined, {
                  onSuccess: (state) =>
                    void navigate(`/learn/${courseId}/${itemId}/quiz/${state.attempt.id}`),
                  onError: (err) =>
                    setStartError(
                      err instanceof ApiError ? err.message : 'Could not start the quiz.',
                    ),
                });
              }}
            >
              {data.attempts.length > 0 ? 'Start another attempt' : 'Start the quiz'}
            </Button>

            {exhausted ? (
              <Text size="sm" c="dimmed">
                You have used all of your attempts.
              </Text>
            ) : null}
          </Group>
        ) : null}
      </Stack>
    </Container>
  );
}
