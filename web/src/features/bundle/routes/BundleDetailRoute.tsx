import { Alert, Badge, Button, Card, Group, Stack, Text, Title } from '@mantine/core';
import { IconCheck, IconInfoCircle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';

import { useAddToCart } from '@/features/commerce/api/queries';
import { formatMinor } from '@/shared/lib/money';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { bundleQuery } from '../api/queries';
import type { Bundle } from '../api/types';

/**
 * A bundle as a buyer sees it.
 *
 * The saving is the whole argument for buying one, so it is stated plainly
 * and computed by the SERVER from the same prices checkout will read — not
 * added up here from numbers the page may not have all of.
 */
export function BundleDetailRoute() {
  const { slug = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(bundleQuery(slug));

  if (isPending) return <LoadingState rows={3} height={120} label="Loading bundle" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <BundleDetail bundle={data} />;
}

function BundleDetail({ bundle }: { bundle: Bundle }) {
  const addToCart = useAddToCart();

  const owned = new Set(bundle.owned_course_ids ?? []);
  const courses = bundle.courses ?? [];
  const newToThem = courses.filter((course) => !owned.has(course.ref));

  const price = bundle.price;
  const parts = bundle.parts_total_minor ?? 0;
  const saving = price && parts > price.amount_minor ? parts - price.amount_minor : 0;

  return (
    <>
      <PageHeader title={bundle.title} description={bundle.subtitle ?? undefined} />

      <Stack gap="md" maw={860}>
        {bundle.description ? <Text>{bundle.description}</Text> : null}

        {/*
          Partial overlap does not block the sale, so the page owes them a
          plain statement of what is new BEFORE they pay — not a refusal, and
          not a surprise afterwards.
        */}
        {owned.size > 0 && newToThem.length > 0 ? (
          <Alert color="warning" icon={<IconInfoCircle size={16} />} role="note">
            You already have {owned.size} of these {courses.length} courses. Buying this bundle
            adds the other {newToThem.length}.
          </Alert>
        ) : null}

        {owned.size > 0 && newToThem.length === 0 ? (
          <Alert color="warning" icon={<IconInfoCircle size={16} />} role="note">
            You already own every course in this bundle.
          </Alert>
        ) : null}

        <Card withBorder>
          <Group justify="space-between" gap="sm">
            <Stack gap={2}>
              {price ? (
                <>
                  <Text fw={700} fz="xl">
                    {formatMinor(price.amount_minor, price.currency)}
                  </Text>
                  {saving > 0 ? (
                    <Text size="sm" c="dimmed">
                      {formatMinor(parts, price.currency)} bought separately — you save{' '}
                      {formatMinor(saving, price.currency)}
                    </Text>
                  ) : null}
                </>
              ) : (
                <Text c="dimmed">Not available right now.</Text>
              )}
            </Stack>

            <Button
              disabled={!price || newToThem.length === 0}
              loading={addToCart.isPending}
              onClick={() => price && addToCart.mutate(price.product_id)}
            >
              Add to basket
            </Button>
          </Group>
        </Card>

        <Stack gap="xs">
          <Title order={4}>{courses.length} courses</Title>

          {courses.map((course) => (
            <Card key={course.id} withBorder padding="sm">
              <Group justify="space-between" gap="sm">
                <Stack gap={2}>
                  <Text component={Link} to={`/courses/${course.slug}`} fw={600}>
                    {course.title}
                  </Text>
                  {course.subtitle ? (
                    <Text size="sm" c="dimmed">
                      {course.subtitle}
                    </Text>
                  ) : null}
                </Stack>

                {owned.has(course.ref) ? (
                  <Badge color="success" variant="light" leftSection={<IconCheck size={12} />}>
                    Owned
                  </Badge>
                ) : course.price ? (
                  <Text size="sm" c="dimmed" style={{ whiteSpace: 'nowrap' }}>
                    {formatMinor(course.price.amount_minor, course.price.currency)}
                  </Text>
                ) : null}
              </Group>
            </Card>
          ))}
        </Stack>
      </Stack>
    </>
  );
}
