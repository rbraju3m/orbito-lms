import {
  ActionIcon,
  Checkbox,
  Group,
  Image,
  Radio,
  Select,
  Stack,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { IconArrowDown, IconArrowUp } from '@tabler/icons-react';

import type { AnswerPayload, AttemptQuestion } from '../api/types';

export interface QuestionInputProps {
  question: AttemptQuestion;
  value: AnswerPayload | undefined;
  onChange: (answer: AnswerPayload) => void;
  disabled?: boolean;
}

/**
 * One input per question type. Every branch reads and writes the same payload
 * shapes the API documents, so there is exactly one contract to keep in step.
 */
export function QuestionInput({ question, value, onChange, disabled }: QuestionInputProps) {
  const options = question.options ?? [];

  switch (question.type) {
    case 'single_choice':
    case 'true_false':
    case 'image_choice': {
      const selected = (value as { option_id?: number } | undefined)?.option_id;

      return (
        <Radio.Group
          value={selected ? String(selected) : null}
          onChange={(next) => onChange({ option_id: Number(next) })}
          aria-label={question.title}
        >
          <Stack gap="xs" mt="xs">
            {options.map((option) => (
              <Radio
                key={option.id}
                value={String(option.id)}
                disabled={disabled}
                label={
                  option.media_url ? (
                    <Stack gap={4}>
                      <Image src={option.media_url} alt="" h={110} w="auto" fit="contain" />
                      <Text size="sm">{option.label}</Text>
                    </Stack>
                  ) : (
                    option.label
                  )
                }
              />
            ))}
          </Stack>
        </Radio.Group>
      );
    }

    case 'multiple_choice': {
      const selected = (value as { option_ids?: number[] } | undefined)?.option_ids ?? [];

      return (
        <Stack gap="xs" mt="xs">
          <Text size="xs" c="dimmed">
            Choose every answer that applies.
          </Text>
          {options.map((option) => (
            <Checkbox
              key={option.id}
              checked={selected.includes(option.id)}
              disabled={disabled}
              label={option.label}
              onChange={(event) =>
                onChange({
                  option_ids: event.currentTarget.checked
                    ? [...selected, option.id]
                    : selected.filter((id) => id !== option.id),
                })
              }
            />
          ))}
        </Stack>
      );
    }

    case 'short_answer':
      return (
        <TextInput
          mt="xs"
          value={(value as { text?: string } | undefined)?.text ?? ''}
          onChange={(event) => onChange({ text: event.currentTarget.value })}
          disabled={disabled}
          aria-label={question.title}
          placeholder="Your answer"
        />
      );

    case 'long_answer':
      return (
        <Stack gap={4} mt="xs">
          <Textarea
            value={(value as { text?: string } | undefined)?.text ?? ''}
            onChange={(event) => onChange({ text: event.currentTarget.value })}
            disabled={disabled}
            aria-label={question.title}
            autosize
            minRows={5}
            maxRows={16}
            placeholder="Write your answer"
          />
          <Text size="xs" c="dimmed">
            An instructor marks this one by hand.
          </Text>
        </Stack>
      );

    case 'fill_blank': {
      const blanks = (value as { blanks?: string[] } | undefined)?.blanks ?? [];

      return (
        <Stack gap="xs" mt="xs">
          {Array.from({ length: question.blank_count ?? 0 }, (_, index) => (
            <TextInput
              key={index}
              value={blanks[index] ?? ''}
              disabled={disabled}
              label={`Blank ${index + 1}`}
              onChange={(event) => {
                const next = [...blanks];
                next[index] = event.currentTarget.value;
                onChange({ blanks: next });
              }}
            />
          ))}
        </Stack>
      );
    }

    case 'matching':
    case 'image_matching': {
      const pairs = (value as { pairs?: Record<string, string> } | undefined)?.pairs ?? {};
      const targets = question.match_targets ?? [];

      return (
        <Stack gap="xs" mt="xs">
          {options.map((option) => (
            <Group key={option.id} wrap="nowrap" gap="sm">
              {option.media_url ? (
                <Image src={option.media_url} alt="" w={80} h={60} fit="cover" radius="sm" />
              ) : null}
              <Text size="sm" style={{ flex: 1 }}>
                {option.label}
              </Text>
              <Select
                data={targets}
                value={pairs[String(option.id)] ?? null}
                disabled={disabled}
                onChange={(next) =>
                  onChange({ pairs: { ...pairs, [String(option.id)]: next ?? '' } })
                }
                placeholder="Match with…"
                aria-label={`Match for ${option.label}`}
                w={200}
              />
            </Group>
          ))}
        </Stack>
      );
    }

    case 'ordering': {
      // Default to the order served; the learner rearranges from there.
      const current =
        (value as { option_ids?: number[] } | undefined)?.option_ids ?? options.map((o) => o.id);

      const move = (from: number, to: number) => {
        if (to < 0 || to >= current.length) return;
        const next = [...current];
        const [moved] = next.splice(from, 1);
        next.splice(to, 0, moved!);
        onChange({ option_ids: next });
      };

      return (
        <Stack gap="xs" mt="xs">
          <Text size="xs" c="dimmed">
            Put these in the right order.
          </Text>
          {current.map((id, index) => {
            const option = options.find((o) => o.id === id);

            return (
              <Group key={id} wrap="nowrap" gap="xs">
                <Text size="sm" c="dimmed" w={20}>
                  {index + 1}.
                </Text>
                <Text size="sm" style={{ flex: 1 }}>
                  {option?.label}
                </Text>
                <ActionIcon
                  variant="subtle"
                  size="sm"
                  disabled={disabled || index === 0}
                  aria-label={`Move ${option?.label} up`}
                  onClick={() => move(index, index - 1)}
                >
                  <IconArrowUp size={15} />
                </ActionIcon>
                <ActionIcon
                  variant="subtle"
                  size="sm"
                  disabled={disabled || index === current.length - 1}
                  aria-label={`Move ${option?.label} down`}
                  onClick={() => move(index, index + 1)}
                >
                  <IconArrowDown size={15} />
                </ActionIcon>
              </Group>
            );
          })}
        </Stack>
      );
    }

    default:
      return (
        <Text size="sm" c="dimmed" mt="xs">
          This question type cannot be answered here yet.
        </Text>
      );
  }
}
