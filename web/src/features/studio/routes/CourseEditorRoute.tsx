import {
  Alert,
  Badge,
  Button,
  Card,
  Container,
  Grid,
  Group,
  Menu,
  Select,
  Stack,
  TagsInput,
  Text,
  Textarea,
  TextInput,
  Title,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertCircle, IconCheck, IconChevronDown, IconExternalLink } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link, useParams } from 'react-router';
import { z } from 'zod';

import { categoriesQuery } from '@/features/catalog/api/queries';
import { ApiError } from '@/shared/api/errors';
import { applyServerErrors } from '@/shared/lib/form';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  studioCourseQuery,
  useCourseTransition,
  useUpdateCourse,
  type CourseTransition,
} from '../api/queries';
import { PublishChecklistCard } from '../components/PublishChecklistCard';

const schema = z.object({
  title: z.string().min(3).max(180),
  subtitle: z.string().max(255).nullable(),
  description: z.string().max(50000).nullable(),
  category_id: z.string().nullable(),
  level: z.string(),
  visibility: z.string(),
});

type Values = z.infer<typeof schema>;

const FIELDS = ['title', 'subtitle', 'description', 'category_id', 'level', 'visibility'] as const;

const TRANSITION_LABELS: Record<CourseTransition, string> = {
  publish: 'Publish',
  unpublish: 'Move back to draft',
  'submit-review': 'Submit for review',
  'approve-review': 'Approve',
  'reject-review': 'Send back to draft',
  archive: 'Archive',
};

const STATUS_COLOR: Record<string, string> = {
  draft: 'gray',
  in_review: 'warning',
  published: 'success',
  archived: 'gray',
};

export function CourseEditorRoute() {
  const { id = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(studioCourseQuery(id));
  const { data: categories } = useQuery(categoriesQuery());
  const { mutateAsync: save, isPending: isSaving } = useUpdateCourse(id);
  const { mutateAsync: transition, isPending: isTransitioning } = useCourseTransition(id);

  const [formError, setFormError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [transitionError, setTransitionError] = useState<string[] | null>(null);
  const [tags, setTags] = useState<string[]>([]);
  const [objectives, setObjectives] = useState<string[]>([]);

  const {
    register,
    handleSubmit,
    reset,
    setValue,
    watch,
    setError,
    formState: { errors, isDirty },
  } = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {
      title: '',
      subtitle: '',
      description: '',
      category_id: null,
      level: 'all',
      visibility: 'public',
    },
  });

  useEffect(() => {
    if (!data) return;
    reset({
      title: data.title,
      subtitle: data.subtitle ?? '',
      description: data.description ?? '',
      category_id: data.category ? String(data.category.id) : null,
      level: data.level,
      visibility: data.visibility,
    });
    setTags(data.tags ?? []);
    setObjectives(data.detail?.objectives ?? []);
  }, [data, reset]);

  if (isPending) {
    return (
      <Container size="lg" py="lg">
        <LoadingState rows={5} />
      </Container>
    );
  }

  if (isError) {
    return (
      <Container size="lg" py="lg">
        <ErrorState error={error} onRetry={() => void refetch()} />
      </Container>
    );
  }

  const categoryOptions = (categories ?? []).flatMap((parent) => [
    { value: String(parent.id), label: parent.name },
    ...(parent.children ?? []).map((child) => ({
      value: String(child.id),
      label: `  ${child.name}`,
    })),
  ]);

  const onSubmit = handleSubmit(async (values) => {
    setFormError(null);
    setSaved(false);
    try {
      await save({
        title: values.title,
        subtitle: values.subtitle || null,
        description: values.description || null,
        category_id: values.category_id ? Number(values.category_id) : null,
        level: values.level,
        visibility: values.visibility,
        tags,
        detail: { objectives },
      });
      setSaved(true);
    } catch (err) {
      setFormError(applyServerErrors(err, setError, FIELDS));
    }
  });

  const runTransition = async (name: CourseTransition) => {
    setTransitionError(null);
    try {
      await transition({ transition: name });
    } catch (err) {
      // A refused publish carries the failed checks; showing only "failed"
      // would leave the instructor guessing.
      setTransitionError(
        err instanceof ApiError && err.details.length > 0
          ? err.details.map((detail) => detail.message)
          : [err instanceof ApiError ? err.message : 'Something went wrong.'],
      );
    }
  };

  const available = (data.allowed_transitions ?? []).length > 0;

  return (
    <Container size="lg" py="lg">
      <PageHeader
        title={data.title}
        description="Course basics. The curriculum builder arrives in Phase 5."
        actions={
          <Group gap="xs">
            <Badge size="lg" color={STATUS_COLOR[data.status] ?? 'gray'} variant="light">
              {data.status_label}
            </Badge>

            {data.status === 'published' ? (
              <Button
                component={Link}
                to={`/courses/${data.slug}`}
                variant="subtle"
                leftSection={<IconExternalLink size={16} />}
              >
                View
              </Button>
            ) : null}

            {available ? (
              <Menu position="bottom-end" withinPortal>
                <Menu.Target>
                  <Button rightSection={<IconChevronDown size={16} />} loading={isTransitioning}>
                    Actions
                  </Button>
                </Menu.Target>
                <Menu.Dropdown>
                  {data.status !== 'published' ? (
                    <Menu.Item onClick={() => void runTransition('publish')}>
                      {TRANSITION_LABELS.publish}
                    </Menu.Item>
                  ) : null}
                  {data.status === 'draft' ? (
                    <Menu.Item onClick={() => void runTransition('submit-review')}>
                      {TRANSITION_LABELS['submit-review']}
                    </Menu.Item>
                  ) : null}
                  {data.status === 'published' ? (
                    <Menu.Item onClick={() => void runTransition('unpublish')}>
                      {TRANSITION_LABELS.unpublish}
                    </Menu.Item>
                  ) : null}
                  {data.status !== 'archived' ? (
                    <Menu.Item color="danger" onClick={() => void runTransition('archive')}>
                      {TRANSITION_LABELS.archive}
                    </Menu.Item>
                  ) : null}
                </Menu.Dropdown>
              </Menu>
            ) : null}
          </Group>
        }
      />

      {transitionError ? (
        <Alert
          color="warning"
          icon={<IconAlertCircle size={16} />}
          mb="md"
          role="alert"
          title="Not published"
        >
          <Stack gap={2}>
            {transitionError.map((message) => (
              <Text key={message} size="sm">
                {message}
              </Text>
            ))}
          </Stack>
        </Alert>
      ) : null}

      <Grid gap="lg">
        <Grid.Col span={{ base: 12, md: 8 }}>
          <Card>
            <form onSubmit={onSubmit} noValidate>
              <Stack gap="md">
                <Title order={3}>Basics</Title>

                {formError ? (
                  <Alert color="danger" icon={<IconAlertCircle size={16} />} role="alert">
                    {formError}
                  </Alert>
                ) : null}

                {saved ? (
                  <Alert color="success" icon={<IconCheck size={16} />} role="status">
                    Changes saved.
                  </Alert>
                ) : null}

                <TextInput
                  {...register('title')}
                  label="Title"
                  error={errors.title?.message}
                  required
                />
                <TextInput
                  {...register('subtitle')}
                  label="Subtitle"
                  error={errors.subtitle?.message}
                />
                <Textarea
                  {...register('description')}
                  label="Description"
                  description="At least 50 characters before you can publish."
                  autosize
                  minRows={6}
                  maxRows={20}
                  error={errors.description?.message}
                />

                <Group grow>
                  <Select
                    data={categoryOptions}
                    value={watch('category_id')}
                    onChange={(value) => setValue('category_id', value, { shouldDirty: true })}
                    label="Category"
                    placeholder="Choose a category"
                    searchable
                    clearable
                  />
                  <Select
                    data={[
                      { value: 'beginner', label: 'Beginner' },
                      { value: 'intermediate', label: 'Intermediate' },
                      { value: 'advanced', label: 'Advanced' },
                      { value: 'all', label: 'All levels' },
                    ]}
                    value={watch('level')}
                    onChange={(value) => setValue('level', value ?? 'all', { shouldDirty: true })}
                    label="Level"
                  />
                </Group>

                <Select
                  data={[
                    { value: 'public', label: 'Public — listed in the catalogue' },
                    { value: 'unlisted', label: 'Unlisted — reachable by link only' },
                    { value: 'private', label: 'Private — enrolled learners only' },
                  ]}
                  value={watch('visibility')}
                  onChange={(value) =>
                    setValue('visibility', value ?? 'public', { shouldDirty: true })
                  }
                  label="Visibility"
                />

                <TagsInput
                  value={tags}
                  onChange={setTags}
                  label="Tags"
                  description="Up to 15. Press Enter after each."
                  maxTags={15}
                />

                <TagsInput
                  value={objectives}
                  onChange={setObjectives}
                  label="What learners will be able to do"
                  description="One outcome per entry."
                  maxTags={20}
                />

                <Group justify="flex-end">
                  <Button
                    type="submit"
                    loading={isSaving}
                    disabled={!isDirty && tags === data.tags}
                  >
                    Save changes
                  </Button>
                </Group>
              </Stack>
            </form>
          </Card>
        </Grid.Col>

        <Grid.Col span={{ base: 12, md: 4 }}>
          {data.publish_checklist ? <PublishChecklistCard checks={data.publish_checklist} /> : null}
        </Grid.Col>
      </Grid>
    </Container>
  );
}
