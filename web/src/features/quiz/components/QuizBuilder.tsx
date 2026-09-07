import { Alert, Button, Divider, Group, Modal, Stack, Tabs, Text } from '@mantine/core';
import { modals } from '@mantine/modals';
import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { IconAlertCircle, IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';

import {
  quizBuilderQuery,
  useDeleteQuestion,
  useReorderQuestions,
  useSaveQuestion,
  useUpdateQuizSettings,
} from '../api/builderQueries';
import type { AuthoredQuestion, QuizSettings } from '../api/builderTypes';

import { QuestionEditor } from './QuestionEditor';
import { QuizSettingsForm } from './QuizSettingsForm';
import { SortableQuestionRow } from './SortableQuestionRow';

export interface QuizBuilderProps {
  itemId: string;
}

/** `null` means the modal is closed; `'new'` means it is open on a blank form. */
type Editing = AuthoredQuestion | 'new' | null;

export function QuizBuilder({ itemId }: QuizBuilderProps) {
  const { data, isPending, isError, error, refetch } = useQuery(quizBuilderQuery(itemId));

  const settingsMutation = useUpdateQuizSettings(itemId);
  const saveQuestion = useSaveQuestion(itemId);
  const deleteQuestion = useDeleteQuestion(itemId);
  const reorder = useReorderQuestions(itemId);

  const [editing, setEditing] = useState<Editing>(null);
  const [settingsSaved, setSettingsSaved] = useState(false);

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  if (isPending) return <LoadingState rows={4} height={56} />;
  if (isError) {
    return <ErrorState error={error} onRetry={() => void refetch()} title="Quiz unavailable" />;
  }

  const { settings, questions } = data;
  const totalPoints = questions.reduce((sum, question) => sum + question.points, 0);

  const onDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;

    if (over === null || active.id === over.id) return;

    const from = questions.findIndex((question) => question.id === active.id);
    const to = questions.findIndex((question) => question.id === over.id);

    if (from === -1 || to === -1) return;

    reorder.mutate(arrayMove(questions, from, to).map((question) => question.id));
  };

  const confirmDelete = (question: AuthoredQuestion) =>
    modals.openConfirmModal({
      title: 'Delete this question?',
      children: (
        <Text size="sm">
          “{question.title}” will be removed from this quiz. Attempts already graded keep their
          score.
        </Text>
      ),
      labels: { confirm: 'Delete', cancel: 'Keep it' },
      confirmProps: { color: 'danger' },
      onConfirm: () => deleteQuestion.mutate(question.id),
    });

  return (
    <>
      <Tabs defaultValue="questions">
        <Tabs.List mb="md">
          <Tabs.Tab value="questions">Questions ({questions.length})</Tabs.Tab>
          <Tabs.Tab value="settings">Settings</Tabs.Tab>
        </Tabs.List>

        <Tabs.Panel value="questions">
          <Stack gap="sm">
            {questions.length === 0 ? (
              <EmptyState
                title="No questions yet"
                description="A quiz with no questions cannot be taken."
              />
            ) : (
              <>
                <Group justify="space-between">
                  <Text size="sm" c="dimmed">
                    {totalPoints} points in total
                  </Text>
                  {reorder.isError ? (
                    <Text size="sm" c="danger">
                      The new order could not be saved.
                    </Text>
                  ) : null}
                </Group>

                <DndContext
                  sensors={sensors}
                  collisionDetection={closestCenter}
                  onDragEnd={onDragEnd}
                >
                  <SortableContext
                    items={questions.map((question) => question.id)}
                    strategy={verticalListSortingStrategy}
                  >
                    <Stack gap="xs">
                      {questions.map((question, index) => (
                        <SortableQuestionRow
                          key={question.id}
                          question={question}
                          index={index}
                          onEdit={() => setEditing(question)}
                          onDelete={() => confirmDelete(question)}
                        />
                      ))}
                    </Stack>
                  </SortableContext>
                </DndContext>
              </>
            )}

            <Divider />

            <Group>
              <Button leftSection={<IconPlus size={16} />} onClick={() => setEditing('new')}>
                Add a question
              </Button>
            </Group>

            {deleteQuestion.isError ? (
              <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
                That question could not be deleted.
              </Alert>
            ) : null}
          </Stack>
        </Tabs.Panel>

        <Tabs.Panel value="settings">
          <QuizSettingsForm
            settings={settings}
            saving={settingsMutation.isPending}
            saved={settingsSaved}
            onSave={(patch: Partial<QuizSettings>) => {
              setSettingsSaved(false);
              settingsMutation.mutate(patch, { onSuccess: () => setSettingsSaved(true) });
            }}
          />
        </Tabs.Panel>
      </Tabs>

      <Modal
        opened={editing !== null}
        onClose={() => setEditing(null)}
        title={editing === 'new' ? 'New question' : 'Edit question'}
        size="lg"
      >
        {editing !== null ? (
          <QuestionEditor
            // Remounts on a different question, so the form never has to sync
            // props into state after the fact.
            key={editing === 'new' ? 'new' : editing.id}
            question={editing === 'new' ? null : editing}
            saving={saveQuestion.isPending}
            onCancel={() => setEditing(null)}
            onSave={async (draft) => {
              await saveQuestion.mutateAsync({
                ...(editing === 'new' ? {} : { id: editing.id }),
                draft,
              });
              setEditing(null);
            }}
          />
        ) : null}
      </Modal>
    </>
  );
}
