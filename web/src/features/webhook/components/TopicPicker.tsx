import { Checkbox, SimpleGrid, Stack, Text } from '@mantine/core';

import type { WebhookTopicOption } from '../api/types';
import { groupTopics } from '../lib/topics';

export interface TopicPickerProps {
  topics: WebhookTopicOption[];
  value: string[];
  onChange: (value: string[]) => void;
  error?: string | undefined;
}

/**
 * Which events an endpoint receives, grouped the way the API groups them.
 * Each box shows the wire name too — that is what the receiver switches on.
 */
export function TopicPicker({ topics, value, onChange, error }: TopicPickerProps) {
  return (
    <Checkbox.Group
      label="Events"
      description="The endpoint receives only what is ticked."
      value={value}
      onChange={onChange}
      error={error}
    >
      <SimpleGrid cols={{ base: 1, sm: 2 }} spacing="md" mt="xs">
        {groupTopics(topics).map(({ group, topics: members }) => (
          <Stack key={group} gap={6}>
            <Text size="xs" fw={600} c="dimmed" tt="uppercase">
              {group}
            </Text>
            {members.map((topic) => (
              <Checkbox key={topic.value} value={topic.value} label={topic.label} description={topic.value} />
            ))}
          </Stack>
        ))}
      </SimpleGrid>
    </Checkbox.Group>
  );
}
