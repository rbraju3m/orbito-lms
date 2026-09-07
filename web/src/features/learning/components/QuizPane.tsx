import { Button, Group, Paper, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import { IconArrowRight, IconChecklist } from '@tabler/icons-react';
import { Link } from 'react-router';

import type { ItemPayload } from '../api/types';

export interface QuizPaneProps {
  courseId: string;
  item: ItemPayload;
  /** Whether this learner may actually take it — staff and previewers may not. */
  canAttempt: boolean;
}

/**
 * The player's surface for a quiz item. The quiz itself runs on its own route:
 * a countdown and unsaved answers do not belong inside a pane the learner can
 * navigate away from with the Next button.
 */
export function QuizPane({ courseId, item, canAttempt }: QuizPaneProps) {
  const quiz = item.content as { instructions?: string | null; question_count?: number };

  return (
    <Stack gap="lg">
      <Title order={2}>{item.title}</Title>

      <Paper withBorder p="xl">
        <Stack align="center" gap="sm">
          <ThemeIcon size={48} radius="xl" variant="light">
            <IconChecklist size={26} />
          </ThemeIcon>

          <Text fw={600}>Ready when you are</Text>

          {quiz.instructions ? (
            <Text size="sm" c="dimmed" ta="center" maw={520}>
              {quiz.instructions}
            </Text>
          ) : null}

          <Group>
            {canAttempt ? (
              <Button
                component={Link}
                to={`/learn/${courseId}/${item.id}/quiz`}
                rightSection={<IconArrowRight size={16} />}
              >
                Go to the quiz
              </Button>
            ) : (
              <Text size="sm" c="dimmed">
                Enrol in this course to take the quiz.
              </Text>
            )}
          </Group>
        </Stack>
      </Paper>
    </Stack>
  );
}
