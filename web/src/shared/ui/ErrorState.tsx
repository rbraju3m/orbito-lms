import { Button, Center, Code, Group, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import { IconAlertTriangle } from '@tabler/icons-react';

import { ApiError } from '@/shared/api/errors';

export interface ErrorStateProps {
  error: unknown;
  onRetry?: (() => void) | undefined;
  title?: string;
}

/**
 * Renders in place of the content that failed — not as a toast, so the user
 * can see *what* is broken and retry just that.
 *
 * The request id is shown deliberately: it is the only thing that lets support
 * find the matching server log.
 */
export function ErrorState({ error, onRetry, title = 'Something went wrong' }: ErrorStateProps) {
  const apiError = error instanceof ApiError ? error : null;
  const message =
    apiError?.message ?? (error instanceof Error ? error.message : 'An unexpected error occurred.');

  return (
    <Center py="xl">
      <Stack align="center" gap="sm" maw={460} ta="center">
        <ThemeIcon size={56} radius="xl" variant="light" color="danger">
          <IconAlertTriangle size={28} stroke={1.5} />
        </ThemeIcon>

        <Title order={3}>{title}</Title>

        <Text c="dimmed" size="sm">
          {message}
        </Text>

        <Group gap="xs" justify="center">
          {onRetry ? (
            <Button variant="light" onClick={onRetry}>
              Try again
            </Button>
          ) : null}
        </Group>

        {apiError?.requestId ? (
          <Text size="xs" c="dimmed">
            Reference: <Code>{apiError.requestId}</Code>
          </Text>
        ) : null}
      </Stack>
    </Center>
  );
}
