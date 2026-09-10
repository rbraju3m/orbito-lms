import {
  Alert,
  Badge,
  Button,
  Card,
  Group,
  MultiSelect,
  NumberInput,
  Stack,
  Text,
  Textarea,
  TextInput,
} from '@mantine/core';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useParams } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { studioCoursesQuery } from '@/features/studio/api/queries';
import { ApiError } from '@/shared/api/errors';
import { formatMinor } from '@/shared/lib/money';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { BundleChecklist } from '../components/BundleChecklist';
import {
  studioBundleQuery,
  useChangeBundleStatus,
  useSetBundlePrice,
  useUpdateBundle,
} from '../api/queries';
import type { Bundle } from '../api/types';

/**
 * Authoring one bundle: what is in it, what it costs, and whether it may be
 * published yet.
 */
export function BundleEditorRoute() {
  const { id = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(studioBundleQuery(id));

  if (isPending) return <LoadingState rows={4} height={96} label="Loading bundle" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <BundleEditor bundle={data} />;
}

function BundleEditor({ bundle }: { bundle: Bundle }) {
  const { session } = useSession();
  const currency = session?.academy?.currency ?? 'USD';

  const update = useUpdateBundle(bundle.id);
  const setPrice = useSetBundlePrice(bundle.id);
  const changeStatus = useChangeBundleStatus(bundle.id);
  const courses = useQuery(studioCoursesQuery({ status: 'published' }));

  const [title, setTitle] = useState(bundle.title);
  const [subtitle, setSubtitle] = useState(bundle.subtitle ?? '');
  const [description, setDescription] = useState(bundle.description ?? '');
  const [selected, setSelected] = useState<string[]>(
    (bundle.courses ?? []).map((course) => String(course.ref)),
  );
  /*
   * Mantine's NumberInput reports a STRING for anything not yet canonical
   * ("070", "1.", ""), so this is typed as the union rather than number —
   * `typeof value === 'number'` on a half-typed field silently becomes the
   * fallback (§ Phase 7).
   */
  const [amount, setAmount] = useState<string | number>(bundle.price?.amount_minor ?? '');

  const failed = [update.error, setPrice.error, changeStatus.error]
    .find((candidate): candidate is ApiError => candidate instanceof ApiError);

  const options = (courses.data?.data ?? []).map((course) => ({
    value: String(course.ref),
    label: course.title,
  }));

  const save = () => {
    update.mutate({
      title,
      subtitle: subtitle || null,
      description: description || null,
      // The WHOLE collection. The server replaces rather than merges, so two
      // people editing one bundle cannot interleave into a set neither asked for.
      course_ids: selected.map(Number),
    });
  };

  const savePrice = () => {
    if (typeof amount !== 'number' || amount < 1) return;
    setPrice.mutate({ currency, amount_minor: amount });
  };

  return (
    <>
      <PageHeader
        title={bundle.title}
        description={`${bundle.status_label} · ${bundle.courses?.length ?? 0} courses`}
        actions={
          <Group gap="xs">
            {bundle.status === 'published' ? (
              <Button
                variant="light"
                loading={changeStatus.isPending}
                onClick={() => changeStatus.mutate('unpublish')}
              >
                Unpublish
              </Button>
            ) : (
              <Button
                loading={changeStatus.isPending}
                onClick={() => changeStatus.mutate('publish')}
              >
                Publish
              </Button>
            )}
          </Group>
        }
      />

      <Stack gap="md" maw={760}>
        {failed ? (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {failed.message}
          </Alert>
        ) : null}

        {bundle.checklist ? <BundleChecklist checks={bundle.checklist} /> : null}

        <Card withBorder>
          <Stack gap="sm">
            <Text fw={600}>Details</Text>

            <TextInput
              label="Title"
              value={title}
              onChange={(event) => setTitle(event.currentTarget.value)}
            />
            <TextInput
              label="Subtitle"
              description="One line that says what the bundle is for."
              value={subtitle}
              onChange={(event) => setSubtitle(event.currentTarget.value)}
            />
            <Textarea
              label="Description"
              autosize
              minRows={3}
              value={description}
              onChange={(event) => setDescription(event.currentTarget.value)}
            />

            <MultiSelect
              label="Courses in this bundle"
              placeholder="Pick published courses"
              description="Two or more, all published. The order here is the order buyers see."
              data={options}
              value={selected}
              onChange={setSelected}
              searchable
              clearable
            />

            <Group justify="flex-end">
              <Button loading={update.isPending} onClick={save}>
                Save
              </Button>
            </Group>
          </Stack>
        </Card>

        <Card withBorder>
          <Stack gap="sm">
            <Text fw={600}>Price</Text>
            <Text size="sm" c="dimmed">
              In {currency}, in minor units — {formatMinor(100, currency)} is entered as 100.
            </Text>

            <Group align="flex-end" gap="sm">
              <NumberInput
                label={`Amount (${currency})`}
                min={1}
                value={amount}
                onChange={setAmount}
                flex={1}
              />
              <Button
                variant="light"
                loading={setPrice.isPending}
                disabled={typeof amount !== 'number'}
                onClick={savePrice}
              >
                Set price
              </Button>
            </Group>

            {bundle.parts_total_minor !== undefined && bundle.parts_total_minor > 0 ? (
              <Text size="sm" c="dimmed">
                These courses cost {formatMinor(bundle.parts_total_minor, currency)} bought
                separately.
              </Text>
            ) : null}
          </Stack>
        </Card>

        {bundle.status === 'published' ? (
          <Group justify="flex-end">
            <Badge color="success" variant="light">
              Live in the catalogue
            </Badge>
          </Group>
        ) : null}
      </Stack>
    </>
  );
}
