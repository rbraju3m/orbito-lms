import { Group, Stack, Text, Title } from '@mantine/core';
import type { ReactNode, Ref } from 'react';

export interface PageHeaderProps {
  title: string;
  description?: string;
  actions?: ReactNode;
  /**
   * For a page that moves focus to its heading — a pager below the grid does,
   * so the reader lands at the top of the new page. Makes the title focusable.
   */
  titleRef?: Ref<HTMLHeadingElement>;
}

export function PageHeader({ title, description, actions, titleRef }: PageHeaderProps) {
  return (
    <Group justify="space-between" align="flex-start" wrap="wrap" mb="lg">
      <Stack gap={2}>
        <Title
          order={1}
          ref={titleRef}
          {...(titleRef ? { tabIndex: -1, style: { outline: 'none' } } : {})}
        >
          {title}
        </Title>
        {description ? (
          <Text c="dimmed" size="sm">
            {description}
          </Text>
        ) : null}
      </Stack>

      {actions ? <Group gap="xs">{actions}</Group> : null}
    </Group>
  );
}
