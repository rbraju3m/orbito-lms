import { Button, Group, NumberInput, Select, Stack, Switch, Textarea } from '@mantine/core';
import { IconCheck } from '@tabler/icons-react';
import { useState } from 'react';

import type { QuizSettings } from '../api/builderTypes';

import { numberValue, optionalNumberValue } from './numberValue';

export interface QuizSettingsFormProps {
  settings: QuizSettings;
  onSave: (patch: Partial<QuizSettings>) => void;
  saving: boolean;
  saved: boolean;
}

export function QuizSettingsForm({ settings, onSave, saving, saved }: QuizSettingsFormProps) {
  const [draft, setDraft] = useState<QuizSettings>(settings);

  const set = <K extends keyof QuizSettings>(key: K, value: QuizSettings[K]) =>
    setDraft((current) => ({ ...current, [key]: value }));

  return (
    <Stack gap="md">
      <Textarea
        value={draft.instructions ?? ''}
        onChange={(event) => set('instructions', event.currentTarget.value || null)}
        label="Instructions"
        description="Shown before the learner starts."
        autosize
        minRows={2}
        maxRows={6}
      />

      <Group grow align="flex-start">
        <NumberInput
          value={draft.time_limit_seconds === null ? '' : draft.time_limit_seconds / 60}
          onChange={(value) => {
            const minutes = optionalNumberValue(value);

            set('time_limit_seconds', minutes === null ? null : Math.round(minutes * 60));
          }}
          label="Time limit (minutes)"
          description="Leave empty for no limit."
          min={1}
          max={1440}
        />
        <Select
          data={[
            { value: 'auto_submit', label: 'Submit what they have' },
            { value: 'auto_abandon', label: 'Discard the attempt' },
          ]}
          value={draft.time_expiry_policy}
          onChange={(value) =>
            set(
              'time_expiry_policy',
              (value as QuizSettings['time_expiry_policy']) ?? 'auto_submit',
            )
          }
          label="When time runs out"
          allowDeselect={false}
        />
      </Group>

      <Group grow align="flex-start">
        <NumberInput
          value={draft.attempts_allowed ?? ''}
          onChange={(value) => set('attempts_allowed', optionalNumberValue(value))}
          label="Attempts allowed"
          description="Leave empty for unlimited."
          min={1}
          max={100}
        />
        <NumberInput
          value={draft.passing_score_percent}
          onChange={(value) => set('passing_score_percent', numberValue(value, 0))}
          label="Pass mark (%)"
          min={0}
          max={100}
        />
      </Group>

      <Group grow align="flex-start">
        <Select
          data={[
            { value: 'highest', label: 'Highest attempt' },
            { value: 'latest', label: 'Latest attempt' },
            { value: 'average', label: 'Average of attempts' },
            { value: 'first', label: 'First attempt' },
          ]}
          value={draft.grading_policy}
          onChange={(value) =>
            set('grading_policy', (value as QuizSettings['grading_policy']) ?? 'highest')
          }
          label="Which attempt counts"
          allowDeselect={false}
        />
        <Select
          data={[
            { value: 'never', label: 'Never' },
            { value: 'submission', label: 'Right after submitting' },
            { value: 'attempts_exhausted', label: 'Once attempts run out' },
          ]}
          value={draft.show_correct_answers_after}
          onChange={(value) =>
            set(
              'show_correct_answers_after',
              (value as QuizSettings['show_correct_answers_after']) ?? 'submission',
            )
          }
          label="Reveal correct answers"
          allowDeselect={false}
        />
      </Group>

      <Group grow align="flex-start">
        <NumberInput
          value={draft.questions_per_page}
          onChange={(value) => set('questions_per_page', numberValue(value, 1))}
          label="Questions per page"
          min={1}
          max={50}
        />
        <NumberInput
          value={draft.questions_per_attempt ?? ''}
          onChange={(value) => set('questions_per_attempt', optionalNumberValue(value))}
          label="Questions per attempt"
          description="Draw a random subset. Empty uses them all."
          min={1}
          max={500}
        />
      </Group>

      <Group>
        <Switch
          checked={draft.question_order === 'random'}
          onChange={(event) =>
            set('question_order', event.currentTarget.checked ? 'random' : 'sorted')
          }
          label="Shuffle questions"
        />
        <Switch
          checked={draft.shuffle_answers}
          onChange={(event) => set('shuffle_answers', event.currentTarget.checked)}
          label="Shuffle answers"
        />
        <Switch
          checked={draft.allow_previous_button}
          onChange={(event) => set('allow_previous_button', event.currentTarget.checked)}
          label="Allow going back"
        />
        <Switch
          checked={draft.hide_question_numbers}
          onChange={(event) => set('hide_question_numbers', event.currentTarget.checked)}
          label="Hide question numbers"
        />
      </Group>

      <Group justify="flex-end">
        {saved ? (
          <Group gap={4} c="var(--mantine-color-success-6)">
            <IconCheck size={16} />
          </Group>
        ) : null}
        <Button loading={saving} onClick={() => onSave(draft)}>
          Save settings
        </Button>
      </Group>
    </Stack>
  );
}
