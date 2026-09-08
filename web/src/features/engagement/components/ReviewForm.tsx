import { Alert, Button, Group, Rating, Stack, Textarea, TextInput } from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle } from '@tabler/icons-react';
import { Controller, useForm } from 'react-hook-form';
import { z } from 'zod';

import { ApiError } from '@/shared/api/errors';

import { useSubmitReview } from '../api/queries';
import type { Review } from '../api/types';

/**
 * The Zod schema is the single source of truth for this form's type.
 *
 * `rating` is required and at least 1 because zero stars is not an opinion —
 * it is the initial state of the widget, and letting it through would post a
 * rating the API rejects with a validation error the learner cannot act on.
 */
const schema = z.object({
  rating: z.number().int().min(1, 'Choose a rating.').max(5),
  title: z.string().max(180).optional(),
  body: z.string().max(5000).optional(),
});

type ReviewFormValues = z.infer<typeof schema>;

export function ReviewForm({
  courseId,
  existing,
  onDone,
}: {
  courseId: string;
  /** The caller's current review, when they have one. This REPLACES it. */
  existing?: Review | undefined;
  onDone?: () => void;
}) {
  const submit = useSubmitReview(courseId);

  const {
    control,
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ReviewFormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      rating: existing?.rating ?? 0,
      title: existing?.title ?? '',
      body: existing?.body ?? '',
    },
  });

  const error = submit.error instanceof ApiError ? submit.error : null;

  return (
    <form
      onSubmit={(event) => {
        void handleSubmit((values) =>
          submit.mutateAsync(
            {
              rating: values.rating,
              title: values.title?.trim() ? values.title : null,
              body: values.body?.trim() ? values.body : null,
            },
            { onSuccess: () => onDone?.() },
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

        <Controller
          control={control}
          name="rating"
          render={({ field }) => (
            <Stack gap={4}>
              <Rating
                value={field.value}
                onChange={field.onChange}
                count={5}
                size="lg"
                aria-label="Your rating"
              />
              {errors.rating ? (
                <span role="alert" style={{ color: 'var(--mantine-color-danger-6)', fontSize: 12 }}>
                  {errors.rating.message}
                </span>
              ) : null}
            </Stack>
          )}
        />

        <TextInput
          label="Headline"
          placeholder="Sum it up in a line"
          error={errors.title?.message}
          {...register('title')}
        />

        <Textarea
          label="Your review"
          placeholder="What worked, what did not, who this course is for."
          minRows={4}
          autosize
          error={errors.body?.message}
          {...register('body')}
        />

        <Group justify="flex-end" gap="xs">
          {onDone ? (
            <Button variant="subtle" onClick={onDone} type="button">
              Cancel
            </Button>
          ) : null}
          <Button type="submit" loading={isSubmitting || submit.isPending}>
            {/* One review per learner: writing again replaces, never appends. */}
            {existing ? 'Update review' : 'Post review'}
          </Button>
        </Group>
      </Stack>
    </form>
  );
}
