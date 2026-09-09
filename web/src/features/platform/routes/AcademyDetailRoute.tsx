import {
  Alert,
  Anchor,
  Badge,
  Button,
  Card,
  Group,
  SimpleGrid,
  Stack,
  Text,
  Title,
} from '@mantine/core';
import {
  IconAlertTriangle,
  IconArrowLeft,
  IconCreditCard,
  IconDoorEnter,
  IconDoorExit,
} from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';
import { formatDateTime } from '@/shared/lib/datetime';
import { formatMinor } from '@/shared/lib/money';
import { ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { tenantQuery, useEnterAcademy, useLeaveAcademy, useTransitionAcademy } from '../api/queries';
import type { Tenant, TenantAction } from '../api/types';
import { AssignPlanModal } from '../components/AssignPlanModal';
import { TenantStatusBadge } from '../components/TenantStatusBadge';
import { TenantTransitionModal } from '../components/TenantTransitionModal';

const ACTION_LABEL: Record<TenantAction, string> = {
  approve: 'Approve',
  reject: 'Reject',
  suspend: 'Suspend',
  reactivate: 'Reinstate',
};

export function AcademyDetailRoute() {
  const { slug = '' } = useParams();
  const { data, isPending, isError, error, refetch } = useQuery(tenantQuery(slug));

  if (isPending) return <LoadingState rows={4} height={96} label="Loading academy" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return <AcademyDetail tenant={data} />;
}

function AcademyDetail({ tenant }: { tenant: Tenant }) {
  const navigate = useNavigate();
  const { session } = useSession();

  const [action, setAction] = useState<TenantAction | null>(null);
  const [planOpen, setPlanOpen] = useState(false);

  const transition = useTransitionAcademy();
  const enter = useEnterAcademy();
  const leave = useLeaveAcademy();

  const isCurrent = session?.academy?.id === tenant.id;
  const switching = enter.isPending || leave.isPending;

  return (
    <>
      <Anchor component={Link} to="/platform/academies" size="sm">
        <Group gap={4} component="span">
          <IconArrowLeft size={14} />
          All academies
        </Group>
      </Anchor>

      <PageHeader
        title={tenant.name}
        description={tenant.slug}
        actions={
          <Group gap="xs" wrap="wrap">
            {tenant.available_actions.map((available) => (
              <Button
                key={available}
                variant={available === 'approve' ? 'filled' : 'default'}
                color={available === 'reject' || available === 'suspend' ? 'danger' : undefined}
                onClick={() => setAction(available)}
              >
                {/* Suspend stays legal on an already-suspended academy — it is
                    how the reason is amended — so it says what it does. */}
                {available === 'suspend' && tenant.status === 'suspended'
                  ? 'Update reason'
                  : ACTION_LABEL[available]}
              </Button>
            ))}

            <Button
              variant="light"
              leftSection={<IconCreditCard size={16} />}
              onClick={() => setPlanOpen(true)}
            >
              Plan
            </Button>
          </Group>
        }
      />

      <Stack gap="md">
        <Group gap="xs">
          <TenantStatusBadge status={tenant.status} label={tenant.status_label} />
          {tenant.is_open ? null : (
            <Badge variant="light" color="gray">
              Members are locked out
            </Badge>
          )}
        </Group>

        {tenant.suspended_reason ? (
          <Alert color="danger" variant="light" icon={<IconAlertTriangle size={16} />}>
            <strong>Suspended:</strong> {tenant.suspended_reason}
          </Alert>
        ) : null}

        {tenant.rejected_reason ? (
          <Alert color="gray" variant="light">
            <strong>Rejected:</strong> {tenant.rejected_reason}
          </Alert>
        ) : null}

        <SimpleGrid cols={{ base: 1, md: 2 }} spacing="md">
          <WorkingInside
            tenant={tenant}
            isCurrent={isCurrent}
            switching={switching}
            onEnter={() =>
              enter.mutate(tenant.slug, { onSuccess: () => void navigate('/dashboard') })
            }
            onLeave={() => leave.mutate(undefined)}
            error={enter.error ?? leave.error}
          />

          <SubscriptionCard tenant={tenant} />
        </SimpleGrid>

        <Card withBorder>
          <Stack gap="xs">
            <Title order={4}>Record</Title>
            <Detail label="Created" value={formatDateTime(tenant.created_at)} />
            <Detail label="Approved" value={formatDateTime(tenant.approved_at)} />
            <Detail label="Support email" value={tenant.support_email ?? '—'} />
            {/* Read-only: the academy's own admin owns this. Shown so support
                can answer "why can nobody sign up?" without asking them. */}
            <Detail label="Sign-ups" value={tenant.registration_mode_label} />
            <Detail label="Database id" value={tenant.id} />
          </Stack>
        </Card>
      </Stack>

      <TenantTransitionModal
        tenant={action === null ? null : tenant}
        action={action}
        pending={transition.isPending}
        error={transition.error}
        onClose={() => {
          transition.reset();
          setAction(null);
        }}
        onConfirm={({ reason }) => {
          if (action === null) return;

          transition.mutate(
            { slug: tenant.slug, action, reason },
            { onSuccess: () => setAction(null) },
          );
        }}
      />

      {planOpen ? <AssignPlanModal tenant={tenant} onClose={() => setPlanOpen(false)} /> : null}
    </>
  );
}

/**
 * The card that explains the thing nothing else in the app has to: a platform
 * operator belongs to no academy, so every product screen is empty until they
 * step inside one.
 */
function WorkingInside({
  tenant,
  isCurrent,
  switching,
  onEnter,
  onLeave,
  error,
}: {
  tenant: Tenant;
  isCurrent: boolean;
  switching: boolean;
  onEnter: () => void;
  onLeave: () => void;
  error: unknown;
}) {
  return (
    <Card withBorder>
      <Stack gap="sm">
        <Title order={4}>Working inside</Title>

        {isCurrent ? (
          <Text size="sm" c="dimmed">
            You are inside this academy. Courses, learners and grading all resolve against it.
          </Text>
        ) : (
          <Text size="sm" c="dimmed">
            Step inside to use this academy&rsquo;s own screens. You hold Super Admin in every
            academy, so nothing further needs granting.
          </Text>
        )}

        {!tenant.is_open ? (
          <Text size="xs" c="dimmed">
            This academy is closed to its members. You can still enter it — that is the point of
            being an operator — but the product screens will be as its staff would find them.
          </Text>
        ) : null}

        {error instanceof Error ? (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {error.message}
          </Alert>
        ) : null}

        <Group gap="xs">
          {isCurrent ? (
            <Button
              variant="default"
              leftSection={<IconDoorExit size={16} />}
              loading={switching}
              onClick={onLeave}
            >
              Step out
            </Button>
          ) : (
            <Button
              leftSection={<IconDoorEnter size={16} />}
              loading={switching}
              onClick={onEnter}
            >
              Enter academy
            </Button>
          )}
        </Group>
      </Stack>
    </Card>
  );
}

function SubscriptionCard({ tenant }: { tenant: Tenant }) {
  const subscription = tenant.subscription;

  if (!subscription) {
    return (
      <Card withBorder>
        <Stack gap="xs">
          <Title order={4}>Subscription</Title>
          <Text size="sm" c="dimmed">
            No subscription on record. Assign a plan to give this academy one.
          </Text>
        </Stack>
      </Card>
    );
  }

  const limits = Object.entries(subscription.plan?.limits ?? {});

  return (
    <Card withBorder>
      <Stack gap="xs">
        <Group justify="space-between" wrap="nowrap">
          <Title order={4}>Subscription</Title>
          <Badge variant="light" color={subscription.permits_writes ? 'success' : 'danger'}>
            {subscription.status_label}
          </Badge>
        </Group>

        {!subscription.permits_writes ? (
          <Text size="sm" c="danger">
            This academy cannot save changes. Reading and exporting still work — a lapse is 402,
            never 403.
          </Text>
        ) : null}

        <Detail
          label="Plan"
          value={
            subscription.plan
              ? `${subscription.plan.name} — ${formatMinor(subscription.plan.price_minor, subscription.plan.currency)}`
              : '—'
          }
        />
        <Detail label="Trial ends" value={formatDateTime(subscription.trial_ends_at)} />
        <Detail label="Period ends" value={formatDateTime(subscription.current_period_ends_at)} />
        <Detail label="Grace ends" value={formatDateTime(subscription.grace_ends_at)} />

        {limits.length > 0 ? (
          <Stack gap={2} mt="xs">
            <Text size="xs" fw={600} tt="uppercase" c="dimmed">
              Plan limits
            </Text>
            {limits.map(([key, value]) => (
              <Detail key={key} label={key} value={value === null ? 'Unlimited' : String(value)} />
            ))}
            <Text size="xs" c="dimmed" mt={4}>
              Counted, but not yet enforced anywhere.
            </Text>
          </Stack>
        ) : null}
      </Stack>
    </Card>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <Group justify="space-between" gap="md" wrap="nowrap">
      <Text size="sm" c="dimmed">
        {label}
      </Text>
      <Text size="sm" ta="right" style={{ wordBreak: 'break-word' }}>
        {value}
      </Text>
    </Group>
  );
}
