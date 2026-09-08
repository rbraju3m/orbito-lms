import { Alert, Button, Group, Stack, Textarea, TextInput } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle } from '@tabler/icons-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { ApiError } from '@/shared/api/errors';

import { useAskQuestion } from '../api/queries';

const schema = z.object({
  title: z.string().trim().min(5, 'Say what you are asking in a line.').max(200),
  body: z.string().trim().min(1, 'Add some detail.').max(5000),
});

type AskFormValues = z.infer<typeof schema>;

export function AskQuestionForm({
  courseId,
  itemId,
  onDone,
}: {
  courseId: string;
  /** Attaches the thread to the lesson being watched, when there is one. */
  itemId?: string | undefined;
  onDone?: () => void;
}) {
  const ask = useAskQuestion(courseId);

  const {
    register,
    handleSubmit,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<AskFormValues>({ resolver: zodResolver(schema) });

  const error = ask.error instanceof ApiError ? ask.error : null;

  return (
    <form
      onSubmit={(event) => {
        void handleSubmit((values) =>
          ask.mutateAsync(
            { ...values, ...(itemId ? { item_id: itemId } : {}) },
            {
              onSuccess: () => {
                reset();
                onDone?.();
              },
            },
          ),
        )(event);
      }}
    >
      <Stack gap="sm">
        {error ? (
          <Alert color="danger" icon={<IconAlertCircle size={16} />}>
            {error.message}
          </Alert>
        ) : null}

        <TextInput
          label="Question"
          placeholder="What are you stuck on?"
          error={errors.title?.message}
          {...register('title')}
        />

        <Textarea
          label="Details"
          placeholder="What have you tried, and what happened?"
          minRows={3}
          autosize
          error={errors.body?.message}
          {...register('body')}
        />

        <Group justify="flex-end" gap="xs">
          {onDone ? (
            <Button variant="subtle" type="button" onClick={onDone}>
              Cancel
            </Button>
          ) : null}
          <Button type="submit" loading={isSubmitting || ask.isPending}>
            Ask
          </Button>
        </Group>
      </Stack>
    </form>
  );
}
