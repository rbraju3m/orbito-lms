import { Badge, Button, Card, Group, Menu, Modal, Stack, Text } from '@mantine/core';
import { useDisclosure } from '@mantine/hooks';
import { IconBroadcast, IconDots, IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { useAddToCart } from '@/features/commerce/api/queries';
import { PriceTag } from '@/features/commerce/components/PriceTag';
import { formatDateTime } from '@/shared/lib/datetime';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  useChangeWebinarStatus,
  useDeleteWebinar,
  useWebinarRegistration,
  webinarsQuery,
} from '../api/queries';
import type { Webinar } from '../api/types';
import { WebinarForm } from '../components/WebinarForm';

/** The zone a new webinar records — whoever schedules it. */
const BROWSER_ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone;

/** What each transition is called to the person pressing it. */
const ACTION_LABEL: Record<string, string> = {
  published: 'Publish',
  draft: 'Take back to draft',
  cancelled: 'Call it off',
};

/**
 * Standalone live events.
 *
 * Members-only, which follows from the tenancy design rather than a product
 * choice — there is no anonymous surface to register from. It is still the one
 * live format not gated on buying a course.
 */
export function WebinarsRoute() {
  const { data, isPending, isError, error, refetch } = useQuery(webinarsQuery());
  const [editing, setEditing] = useState<Webinar | 'new' | null>(null);

  if (isPending) return <LoadingState rows={3} height={90} />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  const canManage = data.meta.can_manage;

  const schedule = canManage ? (
    <Button leftSection={<IconPlus size={14} />} onClick={() => setEditing('new')}>
      Schedule a webinar
    </Button>
  ) : null;

  const form = (
    <Modal
      opened={editing !== null}
      onClose={() => setEditing(null)}
      title={editing === 'new' ? 'Schedule a webinar' : 'Edit webinar'}
      centered
    >
      {editing !== null && (
        <WebinarForm
          existing={editing === 'new' ? null : editing}
          providers={data.meta.providers}
          timezone={BROWSER_ZONE}
          onDone={() => setEditing(null)}
        />
      )}
    </Modal>
  );

  if (data.data.length === 0) {
    return (
      <>
        {/* No header button here: the empty state already offers it, and two
            identical buttons is a choice nobody has to make. */}
        <PageHeader title="Webinars" />
        <EmptyState
          icon={IconBroadcast}
          title="No webinars scheduled"
          description={
            canManage
              ? 'A webinar is a live session open to the whole academy, whether or not somebody is on a course. It starts as a draft, so nothing is announced until you publish it.'
              : 'Open sessions run by the academy appear here, whether or not you are on a course.'
          }
          action={canManage ? { label: 'Schedule a webinar', onClick: () => setEditing('new') } : undefined}
        />
        {form}
      </>
    );
  }

  return (
    <>
      <PageHeader
        title="Webinars"
        description="Open to everybody in the academy."
        actions={schedule}
      />

      <Stack gap="sm">
        {data.data.map((webinar) => (
          <WebinarCard key={webinar.id} webinar={webinar} onEdit={() => setEditing(webinar)} />
        ))}
      </Stack>

      {form}
    </>
  );
}

/**
 * What an author may do with one row.
 *
 * Every item is the server's answer — `available_actions` from the rule
 * `ChangeWebinarStatus` enforces, `is_deletable` from whether anybody has
 * registered — so a button that would 409 cannot be rendered. Publish is the
 * one that can be present and refused, which is why it is disabled with a
 * reason when there is no session rather than hidden.
 */
function ManageMenu({ webinar, onEdit }: { webinar: Webinar; onEdit: () => void }) {
  const status = useChangeWebinarStatus();
  const remove = useDeleteWebinar();
  const [confirming, { open, close }] = useDisclosure(false);

  const actions = webinar.available_actions ?? [];

  return (
    <>
      <Menu position="bottom-end" withinPortal>
        <Menu.Target>
          <Button
            variant="subtle"
            size="compact-sm"
            aria-label={`Manage ${webinar.title}`}
            loading={status.isPending}
          >
            <IconDots size={16} />
          </Button>
        </Menu.Target>
        <Menu.Dropdown>
          <Menu.Item onClick={onEdit}>Edit</Menu.Item>

          {actions.map((action) => {
            const blocked = action === 'published' && webinar.is_publishable === false;

            return (
              <Menu.Item
                key={action}
                color={action === 'cancelled' ? 'red' : undefined}
                disabled={blocked}
                onClick={() => status.mutate({ id: webinar.id, status: action })}
              >
                {ACTION_LABEL[action] ?? action}
                {/*
                 * WHY it cannot be published, from the same rule the API
                 * enforces. A disabled button with no reason is the dead end
                 * the server went out of its way to avoid.
                 */}
                {blocked && webinar.publish_blockers?.[0] ? (
                  <Text size="xs" c="dimmed" component="span" display="block">
                    {webinar.publish_blockers[0].message}
                  </Text>
                ) : null}
              </Menu.Item>
            );
          })}

          {webinar.is_deletable && (
            <>
              <Menu.Divider />
              <Menu.Item color="red" onClick={open}>
                Delete
              </Menu.Item>
            </>
          )}
        </Menu.Dropdown>
      </Menu>

      <Modal opened={confirming} onClose={close} title={`Delete ${webinar.title}?`} centered>
        <Stack gap="sm">
          <Text size="sm">
            Nobody has registered, so there is no record to keep. The session it was going to
            happen at is called off rather than deleted — that is where any attendance lives.
          </Text>
          <Group justify="flex-end">
            <Button variant="subtle" onClick={close}>
              Keep it
            </Button>
            <Button
              color="red"
              loading={remove.isPending}
              onClick={() => remove.mutate(webinar.id, { onSuccess: close })}
            >
              Delete
            </Button>
          </Group>
        </Stack>
      </Modal>
    </>
  );
}

function WebinarCard({ webinar, onEdit }: { webinar: Webinar; onEdit: () => void }) {
  const registration = useWebinarRegistration();
  const addToCart = useAddToCart();
  const full = webinar.places_remaining === 0 && !webinar.is_registered;

  // A ticket, not a sign-up sheet: registering free at a paid event is a 423,
  // so the button offers the basket instead of a refusal.
  const buying = webinar.is_paid && !webinar.is_registered;
  const price = webinar.price ?? null;

  return (
    <Card withBorder>
      <Group justify="space-between" wrap="nowrap" align="flex-start" gap="md">
        <Stack gap={4} style={{ minWidth: 0 }}>
          <Group gap="xs" wrap="wrap">
            <Text fw={600}>{webinar.title}</Text>
            {webinar.is_paid && price ? (
              <PriceTag
                amountMinor={price.amount_minor}
                currency={price.currency}
                listAmountMinor={price.list_amount_minor}
                size="sm"
              />
            ) : null}
            {webinar.status !== 'published' ? (
              <Badge size="xs" variant="light" color="warning">
                {webinar.status_label}
              </Badge>
            ) : null}
            {webinar.is_registered ? (
              <Badge size="xs" variant="light" color="success">
                Registered
              </Badge>
            ) : null}
          </Group>

          {webinar.session ? (
            <Text size="sm" c="dimmed">
              {formatDateTime(webinar.session.starts_at)} · {webinar.session.timezone}
            </Text>
          ) : (
            <Text size="sm" c="dimmed">
              Date to be announced
            </Text>
          )}

          {webinar.description ? (
            <Text size="sm" lineClamp={2}>
              {webinar.description}
            </Text>
          ) : null}

          {/*
           * Null places means uncapped, which is not the same as zero left —
           * so only a real number is ever shown.
           */}
          {webinar.places_remaining !== null ? (
            <Text size="xs" c={webinar.places_remaining === 0 ? 'danger' : 'dimmed'}>
              {webinar.places_remaining === 0 ? 'Full' : `${webinar.places_remaining} places left`}
            </Text>
          ) : null}
        </Stack>

        <Group gap="xs" wrap="nowrap">
          {buying ? (
            <Button
              size="compact-sm"
              disabled={full || !price || webinar.status !== 'published'}
              loading={addToCart.isPending}
              onClick={() => price && addToCart.mutate(price.product_id)}
            >
              {full ? 'Full' : 'Buy a place'}
            </Button>
          ) : webinar.is_registered && !webinar.can_cancel ? (
            /*
             * A place they BOUGHT. The server refuses to let them drop it —
             * re-registering at a paid event 423s, so the way back in is the
             * one thing they cannot do — and a button that would 409 is a bug,
             * not a permission check.
             */
            <Text size="xs" c="dimmed" ta="right" maw={160}>
              Ask the academy for a refund to give up your place.
            </Text>
          ) : (
            <Button
              size="compact-sm"
              variant={webinar.is_registered ? 'default' : 'filled'}
              disabled={full || webinar.status !== 'published'}
              loading={registration.isPending && registration.variables?.id === webinar.id}
              onClick={() =>
                registration.mutate({ id: webinar.id, register: !webinar.is_registered })
              }
            >
              {webinar.is_registered ? 'Cancel' : full ? 'Full' : 'Register'}
            </Button>
          )}

          {/* Present only when the server said this reader may author it. */}
          {webinar.available_actions !== undefined && (
            <ManageMenu webinar={webinar} onEdit={onEdit} />
          )}
        </Group>
      </Group>
    </Card>
  );
}
