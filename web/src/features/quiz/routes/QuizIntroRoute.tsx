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
  VisuallyHidden,
} from '@mantine/core';
import { IconAlertCircle, IconClock } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';

import { t } from '@/shared/i18n';
import { formatNumber } from '@/shared/lib/number';
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
        <ErrorState
          error={error}
          onRetry={() => void refetch()}
          title={t('quiz.unavailable', 'Quiz unavailable')}
        />
      </Container>
    );
  }

  const exhausted = data.attempts_allowed !== null && data.attempts_used >= data.attempts_allowed;
  const open = data.attempts.find((attempt) => attempt.status === 'in_progress');

  return (
    <Container size="md" py="lg">
      <Stack gap="lg">
        <Stack gap="xs">
          <Title order={1} size="h2">
            {t('quiz.intro.title', 'Quiz')}
          </Title>
          <Group gap="xs">
            <Badge variant="light">
              {data.attempts_allowed === null
                ? t('quiz.intro.unlimited', 'Unlimited attempts')
                : t('quiz.intro.attempts_used', '{used} of {allowed} attempts used', {
                    used: formatNumber(data.attempts_used),
                    allowed: formatNumber(data.attempts_allowed),
                  })}
            </Badge>
          </Group>
        </Stack>

        {startError ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
            {startError}
          </Alert>
        ) : null}

        {open ? (
          <Alert
            color="info"
            icon={<IconClock size={16} />}
            title={t('quiz.intro.open_title', 'You have an attempt open')}
          >
            <Stack gap="sm" align="flex-start">
              <Text size="sm">{t('quiz.intro.open_body', 'Pick up where you left off.')}</Text>
              <Button
                size="xs"
                component={Link}
                to={`/learn/${courseId}/${itemId}/quiz/${open.id}`}
              >
                {t('quiz.intro.resume', 'Resume')}
              </Button>
            </Stack>
          </Alert>
        ) : null}

        {data.attempts.length > 0 ? (
          <Card padding={0}>
            {/* Focusable, so a keyboard can scroll it sideways at 360px. */}
            <Table.ScrollContainer
              minWidth={420}
              tabIndex={0}
              aria-label={t('quiz.intro.attempts', 'Your attempts')}
            >
              <Table striped>
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>{t('quiz.intro.col_attempt', 'Attempt')}</Table.Th>
                    <Table.Th>{t('quiz.intro.col_status', 'Status')}</Table.Th>
                    <Table.Th>{t('quiz.intro.col_score', 'Score')}</Table.Th>
                    <Table.Th>
                      <VisuallyHidden>{t('quiz.intro.col_actions', 'Actions')}</VisuallyHidden>
                    </Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {data.attempts.map((attempt) => (
                    <Table.Tr key={attempt.id}>
                      <Table.Td>
                        {t('quiz.intro.attempt_number', '#{number}', {
                          number: formatNumber(attempt.attempt_number),
                        })}
                      </Table.Td>
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
                        {attempt.percent === undefined
                          ? '—'
                          : `${formatNumber(Math.round(attempt.percent))}%`}
                      </Table.Td>
                      <Table.Td>
                        {attempt.status !== 'in_progress' ? (
                          <Button
                            size="compact-xs"
                            variant="subtle"
                            component={Link}
                            to={`/learn/${courseId}/${itemId}/quiz/${attempt.id}/result`}
                          >
                            {t('quiz.intro.review', 'Review')}
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
                      err instanceof ApiError
                        ? err.message
                        : t('quiz.intro.start_failed', 'Could not start the quiz.'),
                    ),
                });
              }}
            >
              {data.attempts.length > 0
                ? t('quiz.intro.start_another', 'Start another attempt')
                : t('quiz.intro.start', 'Start the quiz')}
            </Button>

            {exhausted ? (
              <Text size="sm" c="dimmed">
                {t('quiz.intro.exhausted', 'You have used all of your attempts.')}
              </Text>
            ) : null}
          </Group>
        ) : null}
      </Stack>
    </Container>
  );
}
