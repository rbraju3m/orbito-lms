import { ActionIcon, Alert, Button, FileButton, Group, Stack, Text, Textarea } from '@mantine/core';
import { IconAlertTriangle, IconPaperclip, IconTrash } from '@tabler/icons-react';
import { useState } from 'react';

import {
  useDeleteMedia,
  useUploadMedia,
  type UploadedMedia,
} from '@/features/media/api/queries';
import { ApiError } from '@/shared/api/errors';

import { useSubmitAssignment } from '../api/queries';
import type { Assignment, SubmissionRules } from '../api/types';

export interface SubmissionFormProps {
  itemId: string;
  assignment: Assignment;
  rules: SubmissionRules;
}

export function SubmissionForm({ itemId, assignment, rules }: SubmissionFormProps) {
  const submit = useSubmitAssignment(itemId);
  const upload = useUploadMedia();
  const remove = useDeleteMedia();

  const [body, setBody] = useState('');
  const [files, setFiles] = useState<UploadedMedia[]>([]);
  const [error, setError] = useState<string | null>(null);

  const full = files.length >= assignment.max_files;

  const attach = (file: File | null) => {
    if (file === null) return;

    setError(null);
    upload.mutate(
      { file, collection: 'submission' },
      {
        onSuccess: (media) => setFiles((current) => [...current, media]),
        onError: (err) =>
          setError(err instanceof ApiError ? err.message : 'That file could not be uploaded.'),
      },
    );
  };

  const drop = (id: string) => setFiles((current) => current.filter((file) => file.id !== id));

  /*
   * Removing a file DELETES it. Dropping it from the list alone left it on the
   * server, counting against the learner's upload quota where they could never
   * find it again. Already gone is the outcome we wanted, so a 404 drops it too.
   */
  const detach = (file: UploadedMedia) => {
    setError(null);
    remove.mutate(file.id, {
      onSuccess: () => drop(file.id),
      onError: (err) => {
        if (err instanceof ApiError && err.isNotFound) {
          drop(file.id);
          return;
        }
        setError(err instanceof ApiError ? err.message : 'That file could not be removed.');
      },
    });
  };

  const hand = () => {
    setError(null);
    submit.mutate(
      {
        ...(assignment.allow_text && body.trim() !== '' ? { body } : {}),
        ...(files.length > 0 ? { media_ids: files.map((file) => file.ref) } : {}),
      },
      {
        onSuccess: () => {
          setBody('');
          setFiles([]);
        },
        onError: (err) =>
          setError(
            err instanceof ApiError
              ? (Object.values(err.fieldErrors())[0] ?? err.message)
              : 'That could not be handed in.',
          ),
      },
    );
  };

  const nothingToSend = body.trim() === '' && files.length === 0;

  return (
    <Stack gap="sm">
      {error ? (
        <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
          {error}
        </Alert>
      ) : null}

      {rules.will_be_late ? (
        <Alert color="warning" icon={<IconAlertTriangle size={16} />}>
          {rules.late_penalty_percent > 0
            ? `This is past the deadline. ${rules.late_penalty_percent}% will be taken off the mark.`
            : 'This is past the deadline. It will be marked as late.'}
        </Alert>
      ) : null}

      {assignment.allow_text ? (
        <Textarea
          value={body}
          onChange={(event) => setBody(event.currentTarget.value)}
          label="Your answer"
          autosize
          minRows={6}
          maxRows={24}
        />
      ) : null}

      {assignment.allow_files ? (
        <Stack gap="xs">
          <Group gap="sm">
            <FileButton onChange={attach} disabled={full || upload.isPending}>
              {(props) => (
                <Button
                  {...props}
                  variant="light"
                  size="xs"
                  loading={upload.isPending}
                  leftSection={<IconPaperclip size={14} />}
                >
                  Attach a file
                </Button>
              )}
            </FileButton>

            <Text size="xs" c="dimmed">
              {files.length} of {assignment.max_files}
              {assignment.allowed_extensions
                ? ` · ${assignment.allowed_extensions.join(', ')}`
                : ''}
              {` · up to ${Math.round(assignment.max_file_size_kb / 1024)} MB each`}
            </Text>
          </Group>

          {files.map((file) => (
            <Group key={file.id} gap="xs" wrap="nowrap">
              <IconPaperclip size={14} />
              <Text size="sm" flex={1} lineClamp={1}>
                {file.original_name}
              </Text>
              <ActionIcon
                variant="subtle"
                color="danger"
                aria-label={`Remove ${file.original_name}`}
                loading={remove.isPending && remove.variables === file.id}
                disabled={remove.isPending}
                onClick={() => detach(file)}
              >
                <IconTrash size={16} />
              </ActionIcon>
            </Group>
          ))}
        </Stack>
      ) : null}

      <Group justify="flex-end">
        <Button loading={submit.isPending} disabled={nothingToSend} onClick={hand}>
          Hand in
        </Button>
      </Group>
    </Stack>
  );
}
