import {
  ActionIcon,
  Alert,
  Button,
  Checkbox,
  Group,
  NumberInput,
  Radio,
  Select,
  Stack,
  Switch,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { IconAlertCircle, IconGripVertical, IconPlus, IconTrash } from '@tabler/icons-react';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';

import type {
  AuthoredOption,
  AuthoredQuestion,
  QuestionDraft,
  QuestionSettings,
} from '../api/builderTypes';
import type { QuestionType } from '../api/types';

import { numberValue } from './numberValue';

const TYPES: Array<{ value: QuestionType; label: string }> = [
  { value: 'single_choice', label: 'Single choice' },
  { value: 'multiple_choice', label: 'Multiple choice' },
  { value: 'true_false', label: 'True or false' },
  { value: 'short_answer', label: 'Short answer' },
  { value: 'long_answer', label: 'Long answer' },
  { value: 'fill_blank', label: 'Fill in the blank' },
  { value: 'matching', label: 'Matching' },
  { value: 'ordering', label: 'Ordering' },
  { value: 'image_choice', label: 'Image choice' },
  { value: 'image_matching', label: 'Image matching' },
];

/** Mirrors QuestionType::hasOptions() — the server rejects the rest. */
const WITHOUT_OPTIONS: QuestionType[] = ['short_answer', 'long_answer', 'fill_blank'];

const hasOptions = (type: QuestionType) => !WITHOUT_OPTIONS.includes(type);
const isSingleAnswer = (type: QuestionType) =>
  type === 'single_choice' || type === 'true_false' || type === 'image_choice';
const isMatching = (type: QuestionType) => type === 'matching' || type === 'image_matching';

const blankOption = (): AuthoredOption => ({ label: '', is_correct: false, match_key: '' });

function defaultOptions(type: QuestionType): AuthoredOption[] {
  if (type === 'true_false') {
    return [
      { label: 'True', is_correct: true },
      { label: 'False', is_correct: false },
    ];
  }

  return hasOptions(type) ? [blankOption(), blankOption()] : [];
}

export interface QuestionEditorProps {
  question: AuthoredQuestion | null;
  onSave: (draft: QuestionDraft) => Promise<unknown>;
  onCancel: () => void;
  saving: boolean;
}

/**
 * One form for every question type.
 *
 * The type drives which fields appear, and switching type resets the options
 * to that type's defaults — carrying, say, four choices into a true/false
 * question would only produce a validation error on save.
 */
export function QuestionEditor({ question, onSave, onCancel, saving }: QuestionEditorProps) {
  const [type, setType] = useState<QuestionType>(question?.type ?? 'single_choice');
  const [title, setTitle] = useState(question?.title ?? '');
  const [body, setBody] = useState(question?.body ?? '');
  const [explanation, setExplanation] = useState(question?.explanation ?? '');
  const [points, setPoints] = useState<number>(question?.points ?? 1);
  const [options, setOptions] = useState<AuthoredOption[]>(
    question?.options && question.options.length > 0
      ? question.options.map((option) => ({ ...option, match_key: option.match_key ?? '' }))
      : defaultOptions(question?.type ?? 'single_choice'),
  );
  const [accepted, setAccepted] = useState<string>((question?.settings?.accepted ?? []).join('\n'));
  const [caseSensitive, setCaseSensitive] = useState(question?.settings?.case_sensitive ?? false);
  const [blanks, setBlanks] = useState<string[]>(
    (question?.settings?.blanks ?? [{ accepted: [] }]).map((blank) => blank.accepted.join(', ')),
  );
  const [error, setError] = useState<string | null>(null);

  const changeType = (next: QuestionType) => {
    setType(next);
    setOptions(defaultOptions(next));
  };

  const patchOption = (index: number, patch: Partial<AuthoredOption>) =>
    setOptions((current) =>
      current.map((option, i) => (i === index ? { ...option, ...patch } : option)),
    );

  const chooseCorrect = (index: number) =>
    setOptions((current) => current.map((option, i) => ({ ...option, is_correct: i === index })));

  const buildSettings = (): QuestionSettings | undefined => {
    if (type === 'short_answer') {
      return {
        accepted: accepted
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean),
        case_sensitive: caseSensitive,
      };
    }

    if (type === 'fill_blank') {
      return {
        blanks: blanks.map((blank) => ({
          accepted: blank
            .split(',')
            .map((value) => value.trim())
            .filter(Boolean),
        })),
      };
    }

    return undefined;
  };

  const submit = async () => {
    setError(null);

    const settings = buildSettings();
    const draft: QuestionDraft = {
      type,
      title,
      body: body || null,
      explanation: explanation || null,
      points,
      ...(settings ? { settings } : {}),
      ...(hasOptions(type)
        ? {
            options: options.map((option, index) => ({
              ...(option.id === undefined ? {} : { id: option.id }),
              label: option.label,
              is_correct: option.is_correct ?? false,
              // Ordering grades on the authored order, so position is the
              // answer for that type.
              position: index,
              ...(isMatching(type) ? { match_key: option.match_key ?? '' } : {}),
            })),
          }
        : {}),
    };

    try {
      await onSave(draft);
    } catch (err) {
      setError(
        err instanceof ApiError
          ? (Object.values(err.fieldErrors())[0] ?? err.message)
          : 'Something went wrong.',
      );
    }
  };

  return (
    <Stack gap="md">
      {error ? (
        <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
          {error}
        </Alert>
      ) : null}

      <Group grow align="flex-start">
        <Select
          data={TYPES}
          value={type}
          onChange={(value) => changeType((value as QuestionType) ?? 'single_choice')}
          label="Type"
          allowDeselect={false}
        />
        <NumberInput
          value={points}
          onChange={(value) => setPoints(numberValue(value, 0))}
          label="Points"
          min={0}
          max={1000}
        />
      </Group>

      <TextInput
        value={title}
        onChange={(event) => setTitle(event.currentTarget.value)}
        label="Question"
        required
      />

      <Textarea
        value={body}
        onChange={(event) => setBody(event.currentTarget.value)}
        label="Extra detail"
        description="Optional. Shown under the question."
        autosize
        minRows={2}
        maxRows={8}
      />

      {type === 'long_answer' ? (
        <Alert color="info" variant="light">
          Long answers always wait for a person to grade them.
        </Alert>
      ) : null}

      {type === 'short_answer' ? (
        <Stack gap="xs">
          <Textarea
            value={accepted}
            onChange={(event) => setAccepted(event.currentTarget.value)}
            label="Accepted answers"
            description="One per line. Leave empty to grade these by hand instead."
            autosize
            minRows={3}
          />
          <Switch
            checked={caseSensitive}
            onChange={(event) => setCaseSensitive(event.currentTarget.checked)}
            label="Case sensitive"
          />
        </Stack>
      ) : null}

      {type === 'fill_blank' ? (
        <Stack gap="xs">
          <Text size="sm" fw={500}>
            Blanks
          </Text>
          <Text size="xs" c="dimmed">
            One row per blank, in order. Separate alternative spellings with commas.
          </Text>
          {blanks.map((blank, index) => (
            <Group key={index} gap="xs" wrap="nowrap">
              <TextInput
                flex={1}
                value={blank}
                onChange={(event) => {
                  // Read before the updater runs: a state updater is called
                  // after the event has been released, and `currentTarget` is
                  // null by then.
                  const next = event.currentTarget.value;

                  setBlanks((current) => current.map((value, i) => (i === index ? next : value)));
                }}
                placeholder={`Blank ${index + 1}`}
                aria-label={`Blank ${index + 1}`}
              />
              <ActionIcon
                variant="subtle"
                color="danger"
                aria-label={`Remove blank ${index + 1}`}
                disabled={blanks.length === 1}
                onClick={() => setBlanks((current) => current.filter((_, i) => i !== index))}
              >
                <IconTrash size={16} />
              </ActionIcon>
            </Group>
          ))}
          <Group>
            <Button
              size="xs"
              variant="light"
              leftSection={<IconPlus size={14} />}
              onClick={() => setBlanks((current) => [...current, ''])}
            >
              Add a blank
            </Button>
          </Group>
        </Stack>
      ) : null}

      {hasOptions(type) ? (
        <Stack gap="xs">
          <Text size="sm" fw={500}>
            {type === 'ordering' ? 'Items, in the correct order' : 'Options'}
          </Text>

          {options.map((option, index) => (
            <Group key={index} gap="xs" wrap="nowrap" align="center">
              {type === 'ordering' ? (
                <IconGripVertical size={16} opacity={0.5} />
              ) : isMatching(type) ? null : isSingleAnswer(type) ? (
                <Radio
                  checked={option.is_correct === true}
                  onChange={() => chooseCorrect(index)}
                  aria-label={`Option ${index + 1} is correct`}
                />
              ) : (
                <Checkbox
                  checked={option.is_correct === true}
                  onChange={(event) =>
                    patchOption(index, { is_correct: event.currentTarget.checked })
                  }
                  aria-label={`Option ${index + 1} is correct`}
                />
              )}

              <TextInput
                flex={1}
                value={option.label}
                onChange={(event) => patchOption(index, { label: event.currentTarget.value })}
                placeholder={isMatching(type) ? 'Left side' : `Option ${index + 1}`}
                aria-label={`Option ${index + 1}`}
                disabled={type === 'true_false'}
              />

              {isMatching(type) ? (
                <TextInput
                  flex={1}
                  value={option.match_key ?? ''}
                  onChange={(event) => patchOption(index, { match_key: event.currentTarget.value })}
                  placeholder="Matches with"
                  aria-label={`Option ${index + 1} matches with`}
                />
              ) : null}

              <ActionIcon
                variant="subtle"
                color="danger"
                aria-label={`Remove option ${index + 1}`}
                disabled={options.length <= 2 || type === 'true_false'}
                onClick={() => setOptions((current) => current.filter((_, i) => i !== index))}
              >
                <IconTrash size={16} />
              </ActionIcon>
            </Group>
          ))}

          {type === 'true_false' ? null : (
            <Group>
              <Button
                size="xs"
                variant="light"
                leftSection={<IconPlus size={14} />}
                onClick={() => setOptions((current) => [...current, blankOption()])}
              >
                Add an option
              </Button>
            </Group>
          )}
        </Stack>
      ) : null}

      <Textarea
        value={explanation}
        onChange={(event) => setExplanation(event.currentTarget.value)}
        label="Explanation"
        description="Shown after grading, when the quiz reveals answers."
        autosize
        minRows={2}
        maxRows={6}
      />

      <Group justify="flex-end">
        <Button variant="subtle" onClick={onCancel}>
          Cancel
        </Button>
        <Button loading={saving} onClick={() => void submit()}>
          {question ? 'Save question' : 'Add question'}
        </Button>
      </Group>
    </Stack>
  );
}
