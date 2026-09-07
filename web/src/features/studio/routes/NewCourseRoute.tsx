import {
  Alert,
  Button,
  Card,
  Container,
  Group,
  Select,
  Stack,
  Text,
  TextInput,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useNavigate } from 'react-router';
import { z } from 'zod';

import { categoriesQuery } from '@/features/catalog/api/queries';
import { applyServerErrors } from '@/shared/lib/form';
import { PageHeader } from '@/shared/ui';

import { useCreateCourse } from '../api/queries';

const schema = z.object({
  title: z.string().min(3, 'Give the course a title of at least 3 characters.').max(180),
  subtitle: z.string().max(255).optional(),
  category_id: z.string().optional(),
});

type Values = z.infer<typeof schema>;

/**
 * Step one of course creation, deliberately tiny.
 *
 * Time-to-first-course is the product metric (PRODUCT_VISION §5); asking for
 * everything up front is how that number goes wrong.
 */
export function NewCourseRoute() {
  const navigate = useNavigate();
  const [formError, setFormError] = useState<string | null>(null);
  const { mutateAsync, isPending } = useCreateCourse();
  const { data: categories } = useQuery(categoriesQuery());

  const {
    register,
    handleSubmit,
    setValue,
    setError,
    formState: { errors },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { title: '', subtitle: '' },
  });

  const categoryOptions = (categories ?? []).flatMap((parent) => [
    { value: String(parent.id), label: parent.name },
    ...(parent.children ?? []).map((child) => ({
      value: String(child.id),
      label: `  ${child.name}`,
    })),
  ]);

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    try {
      const course = await mutateAsync({
        title: values.title,
        ...(values.subtitle ? { subtitle: values.subtitle } : {}),
        ...(values.category_id ? { category_id: Number(values.category_id) } : {}),
      });
      void navigate(`/studio/courses/${course.id}`, { replace: true });
    } catch (error) {
      setFormError(applyServerErrors(error, setError, ['title', 'subtitle', 'category_id']));
    }
  });

  return (
    <Container size="sm" py="lg">
      <PageHeader
        title="Create a course"
        description="Just a title to begin. Everything else can wait."
      />

      <Card>
        <form onSubmit={onSubmit} noValidate>
          <Stack gap="md">
            {formError ? (
              <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
                {formError}
              </Alert>
            ) : null}

            <TextInput
              {...register('title')}
              label="Course title"
              placeholder="Introduction to Bengali Poetry"
              error={errors.title?.message}
              required
              autoFocus
            />

            <TextInput
              {...register('subtitle')}
              label="Subtitle"
              description="One line that tells a learner what they'll get. You can add this later."
              error={errors.subtitle?.message}
            />

            <Select
              data={categoryOptions}
              onChange={(value) => setValue('category_id', value ?? undefined)}
              label="Category"
              placeholder="Choose a category"
              description="Required before publishing, optional right now."
              searchable
              clearable
            />

            <Group justify="flex-end">
              <Button type="submit" loading={isPending}>
                Create and continue
              </Button>
            </Group>

            <Text size="xs" c="dimmed">
              Your course starts as a draft. Nobody sees it until you publish.
            </Text>
          </Stack>
        </form>
      </Card>
    </Container>
  );
}
