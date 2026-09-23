import { Alert, Button, Group, Stack, Textarea, TextInput } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle } from '@tabler/icons-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

import { ApiError } from '@/shared/api/errors';
import { t } from '@/shared/i18n';

import { useAskQuestion } from '../api/queries';

// Messages are functions, read when validation runs — after the reader's
// catalogue has loaded — not when this module does.
const schema = z.object({
  title: z
    .string()
    .trim()
    .min(5, {
      error: () => t('engagement.ask.title_required', 'Say what you are asking in a line.'),
    })
    .max(200),
  body: z
    .string()
    .trim()
    .min(1, { error: () => t('engagement.ask.body_required', 'Add some detail.') })
    .max(5000),
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
          label={t('engagement.ask.question', 'Question')}
          placeholder={t('engagement.ask.question_placeholder', 'What are you stuck on?')}
          error={errors.title?.message}
          {...register('title')}
        />

        <Textarea
          label={t('engagement.ask.details', 'Details')}
          placeholder={t(
            'engagement.ask.details_placeholder',
            'What have you tried, and what happened?',
          )}
          minRows={3}
          autosize
          error={errors.body?.message}
          {...register('body')}
        />

        <Group justify="flex-end" gap="xs">
          {onDone ? (
            <Button variant="subtle" type="button" onClick={onDone}>
              {t('engagement.cancel', 'Cancel')}
            </Button>
          ) : null}
          <Button type="submit" loading={isSubmitting || ask.isPending}>
            {t('engagement.ask.submit', 'Ask')}
          </Button>
        </Group>
      </Stack>
    </form>
  );
}
