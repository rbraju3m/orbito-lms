import { ActionIcon, Button, Group, Stack, Text, Textarea } from '@mantine/core';
import { IconTrash } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { t } from '@/shared/i18n';
import { EmptyState, LoadingState } from '@/shared/ui';
import { formatDateTime } from '@/shared/lib/datetime';

import { notesQuery, useAddNote, useDeleteNote } from '../api/queries';

export interface NotesPanelProps {
  itemId: string;
  canWrite: boolean;
}

export function NotesPanel({ itemId, canWrite }: NotesPanelProps) {
  const { data, isPending } = useQuery(notesQuery(itemId));
  const addNote = useAddNote(itemId);
  const deleteNote = useDeleteNote(itemId);
  const [draft, setDraft] = useState('');

  if (!canWrite) {
    return (
      <Text size="sm" c="dimmed">
        {t('learning.notes.enrol', 'Enrol in this course to take notes.')}
      </Text>
    );
  }

  if (isPending) return <LoadingState rows={2} height={48} />;

  return (
    <Stack gap="md">
      <Stack gap="xs">
        <Textarea
          value={draft}
          onChange={(event) => setDraft(event.currentTarget.value)}
          placeholder={t('learning.notes.placeholder', 'Write a note for yourself…')}
          aria-label={t('learning.notes.new', 'New note')}
          autosize
          minRows={2}
          maxRows={8}
        />
        <Group justify="flex-end">
          <Button
            size="xs"
            disabled={!draft.trim()}
            loading={addNote.isPending}
            onClick={() => {
              addNote.mutate({ body: draft.trim() });
              setDraft('');
            }}
          >
            {t('learning.notes.save', 'Save note')}
          </Button>
        </Group>
      </Stack>

      {data && data.length === 0 ? (
        <EmptyState
          title={t('learning.notes.empty_title', 'No notes yet')}
          description={t('learning.notes.empty_body', 'Notes are private to you.')}
        />
      ) : null}

      <Stack gap="xs">
        {data?.map((note) => (
          <Group key={note.id} justify="space-between" align="flex-start" wrap="nowrap">
            <Stack gap={2} style={{ flex: 1 }}>
              <Text size="sm" style={{ whiteSpace: 'pre-wrap' }}>
                {note.body}
              </Text>
              <Text size="xs" c="dimmed">
                {formatDateTime(note.created_at)}
              </Text>
            </Stack>
            <ActionIcon
              variant="subtle"
              color="danger"
              size="sm"
              aria-label={t('learning.notes.delete', 'Delete note')}
              onClick={() => deleteNote.mutate(note.id)}
            >
              <IconTrash size={14} />
            </ActionIcon>
          </Group>
        ))}
      </Stack>
    </Stack>
  );
}
