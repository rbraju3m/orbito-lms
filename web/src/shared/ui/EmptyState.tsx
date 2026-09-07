import { Button, Center, Stack, Text, ThemeIcon, Title } from '@mantine/core';
import type { Icon } from '@tabler/icons-react';
import { IconInbox } from '@tabler/icons-react';
import type { ReactNode } from 'react';

export interface EmptyStateProps {
  /** One short noun phrase: "No courses yet". */
  title: string;
  /** One sentence saying what belongs here. */
  description?: string;
  icon?: Icon;
  action?: { label: string; onClick: () => void } | undefined;
  children?: ReactNode;
}

/**
 * Every empty list uses this. See docs/DESIGN_SYSTEM.md §7 — an empty table
 * with a header and nothing under it is not an acceptable empty state.
 */
export function EmptyState({
  title,
  description,
  icon: IconComponent = IconInbox,
  action,
  children,
}: EmptyStateProps) {
  return (
    <Center py="xl">
      <Stack align="center" gap="sm" maw={420} ta="center">
        <ThemeIcon size={56} radius="xl" variant="light" color="gray">
          <IconComponent size={28} stroke={1.5} />
        </ThemeIcon>

        <Title order={3}>{title}</Title>

        {description ? (
          <Text c="dimmed" size="sm">
            {description}
          </Text>
        ) : null}

        {action ? (
          <Button mt="xs" onClick={action.onClick}>
            {action.label}
          </Button>
        ) : null}

        {children}
      </Stack>
    </Center>
  );
}
