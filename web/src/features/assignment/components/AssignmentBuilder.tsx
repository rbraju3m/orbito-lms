import {
  Alert,
  Button,
  Group,
  NumberInput,
  Select,
  Stack,
  Switch,
  TagsInput,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { IconAlertCircle, IconCheck } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { numberValue, optionalNumberValue } from '@/shared/lib/numberValue';
import { ApiError } from '@/shared/api/errors';
import { fromLocalInputValue, toLocalInputValue } from '@/shared/lib/datetime';
import { ErrorState, LoadingState } from '@/shared/ui';

import { assignmentBuilderQuery, useUpdateAssignment } from '../api/queries';
import type { Assignment, AssignmentDraft, LatePolicy } from '../api/types';

export interface AssignmentBuilderProps {
  itemId: string;
}

const LATE_POLICIES: Array<{ value: LatePolicy; label: string }> = [
  { value: 'accept', label: 'Accept late work in full' },
  { value: 'penalise', label: 'Accept late work with a penalty' },
  { value: 'reject', label: 'Refuse late work' },
];

export function AssignmentBuilder({ itemId }: AssignmentBuilderProps) {
  const { data, isPending, isError, error, refetch } = useQuery(assignmentBuilderQuery(itemId));

  if (isPending) return <LoadingState rows={4} height={60} />;
  if (isError) {
    return (
      <ErrorState error={error} onRetry={() => void refetch()} title="Assignment unavailable" />
    );
  }

  // Keyed by item so switching items remounts the form with the right values.
  return <AssignmentForm key={itemId} itemId={itemId} assignment={data} />;
}

function AssignmentForm({ itemId, assignment }: { itemId: string; assignment: Assignment }) {
  const update = useUpdateAssignment(itemId);

  const [draft, setDraft] = useState<Assignment>(assignment);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);

  const set = <K extends keyof Assignment>(key: K, value: Assignment[K]) => {
    setSaved(false);
    setDraft((current) => ({ ...current, [key]: value }));
  };

  const save = () => {
    setError(null);
    setSaved(false);

    const payload: AssignmentDraft = {
      instructions: draft.instructions,
      total_points: draft.total_points,
      passing_points: draft.passing_points,
      due_at: draft.due_at,
      late_policy: draft.late_policy,
      late_penalty_percent: draft.late_penalty_percent,
      max_attempts: draft.max_attempts,
      allow_text: draft.allow_text,
      allow_files: draft.allow_files,
      max_file_size_kb: draft.max_file_size_kb,
      max_files: draft.max_files,
      allowed_extensions: draft.allowed_extensions ?? [],
    };

    update.mutate(payload, {
      onSuccess: () => setSaved(true),
      onError: (err) =>
        setError(
          err instanceof ApiError
            ? (Object.values(err.fieldErrors())[0] ?? err.message)
            : 'Something went wrong.',
        ),
    });
  };

  return (
    <Stack gap="md">
      {error ? (
        <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
          {error}
        </Alert>
      ) : null}

      {saved ? (
        <Alert color="success" icon={<IconCheck size={16} />} role="status">
          Saved.
        </Alert>
      ) : null}

      <Textarea
        value={draft.instructions ?? ''}
        onChange={(event) => set('instructions', event.currentTarget.value || null)}
        label="Instructions"
        description="What the learner has to do."
        autosize
        minRows={4}
        maxRows={16}
      />

      <Group grow align="flex-start">
        <NumberInput
          value={draft.total_points}
          onChange={(value) => set('total_points', numberValue(value, 1))}
          label="Marks available"
          min={1}
          max={10000}
        />
        <NumberInput
          value={draft.passing_points ?? ''}
          onChange={(value) => set('passing_points', optionalNumberValue(value))}
          label="Pass mark"
          description="Leave empty for no pass or fail."
          min={0}
          max={10000}
        />
      </Group>

      <Group grow align="flex-start">
        <TextInput
          type="datetime-local"
          value={toLocalInputValue(draft.due_at)}
          onChange={(event) => set('due_at', fromLocalInputValue(event.currentTarget.value))}
          label="Due"
          description="Your own time zone. Leave empty for no deadline."
        />
        <NumberInput
          value={draft.max_attempts ?? ''}
          onChange={(value) => set('max_attempts', optionalNumberValue(value))}
          label="Attempts allowed"
          description="Leave empty for unlimited."
          min={1}
          max={50}
        />
      </Group>

      <Select
        data={LATE_POLICIES}
        value={draft.late_policy}
        onChange={(value) => set('late_policy', (value as LatePolicy) ?? 'accept')}
        label="Late work"
        allowDeselect={false}
      />

      {draft.late_policy === 'penalise' ? (
        <NumberInput
          value={draft.late_penalty_percent}
          onChange={(value) => set('late_penalty_percent', numberValue(value, 0))}
          label="Penalty (%)"
          description="Taken off the mark when the work is graded, not before."
          min={1}
          max={100}
        />
      ) : null}

      <Group>
        <Switch
          checked={draft.allow_text}
          onChange={(event) => set('allow_text', event.currentTarget.checked)}
          label="Written answer"
        />
        <Switch
          checked={draft.allow_files}
          onChange={(event) => set('allow_files', event.currentTarget.checked)}
          label="File uploads"
        />
      </Group>

      {!draft.allow_text && !draft.allow_files ? (
        <Alert color="warning" variant="light">
          Allow a written answer, file uploads, or both — otherwise there is no way to hand this in.
        </Alert>
      ) : null}

      {draft.allow_files ? (
        <Group grow align="flex-start">
          <NumberInput
            value={draft.max_files}
            onChange={(value) => set('max_files', numberValue(value, 1))}
            label="Files allowed"
            min={1}
            max={20}
          />
          <NumberInput
            value={draft.max_file_size_kb}
            onChange={(value) => set('max_file_size_kb', numberValue(value, 1024))}
            label="Largest file (KB)"
            min={64}
            max={25600}
          />
        </Group>
      ) : null}

      {draft.allow_files ? (
        <TagsInput
          value={draft.allowed_extensions ?? []}
          onChange={(value) => set('allowed_extensions', value)}
          label="Accepted file types"
          description="Extensions, without the dot. Leave empty to accept anything the platform allows."
          placeholder="pdf"
          clearable
        />
      ) : null}

      <Group justify="flex-end">
        <Text size="xs" c="dimmed">
          Learners see these rules before they hand anything in.
        </Text>
        <Button loading={update.isPending} onClick={save}>
          Save assignment
        </Button>
      </Group>
    </Stack>
  );
}
