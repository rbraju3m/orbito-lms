import { Alert, Button, Group, Modal, Select, Stack, Text, TextInput } from '@mantine/core';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';
import { fromLocalInputValue } from '@/shared/lib/datetime';
import { formatMinor } from '@/shared/lib/money';

import { plansQuery, useAssignPlan } from '../api/queries';
import type { Tenant } from '../api/types';

interface Props {
  tenant: Tenant;
  onClose: () => void;
}

/**
 * Move an academy onto a plan — and renew it.
 *
 * The two are one endpoint deliberately: renewing is what revives a LAPSED
 * academy, and separating them would leave an operator who has just been paid
 * unable to reopen the academy until the nightly sweep, which only ever
 * degrades. The copy says so, because "assign plan" does not sound like
 * "unblock their staff".
 *
 * Rendered only while open, so the caller's `{open && <AssignPlanModal/>}`
 * remounts it every time. That is what keeps the picker seeded from the plan
 * the academy is on NOW — a version that stayed mounted would still be
 * offering the plan it had before the last save.
 */
export function AssignPlanModal({ tenant, onClose }: Props) {
  const { data: plans, isPending: plansPending } = useQuery(plansQuery());
  const assign = useAssignPlan();

  const current = tenant.subscription?.plan?.slug ?? null;
  const [plan, setPlan] = useState<string | null>(current);
  const [periodEndsAt, setPeriodEndsAt] = useState('');

  const apiError = assign.error instanceof ApiError ? assign.error : null;

  const options = (plans ?? []).map((row) => ({
    value: row.slug,
    // The price belongs on the option: an operator choosing between four
    // plans is choosing between four prices.
    label: `${row.name} — ${formatMinor(row.price_minor, row.currency)} / ${row.billing_period}`,
    disabled: !row.is_active && row.slug !== current,
  }));

  const close = () => {
    assign.reset();
    onClose();
  };

  const submit = () => {
    if (plan === null) return;

    assign.mutate(
      { slug: tenant.slug, plan, periodEndsAt: fromLocalInputValue(periodEndsAt) },
      { onSuccess: close },
    );
  };

  return (
    <Modal opened onClose={close} title="Plan and renewal" centered>
      <Stack gap="md">
        <Text size="sm">
          {tenant.name} is on{' '}
          <strong>{tenant.subscription?.plan?.name ?? 'no plan'}</strong>.
        </Text>

        <Select
          data={options}
          value={plan}
          onChange={setPlan}
          label="Plan"
          placeholder={plansPending ? 'Loading plans…' : 'Choose a plan'}
          disabled={plansPending}
          required
        />

        <TextInput
          type="datetime-local"
          label="Paid up until"
          description="Leave empty for one month from now. Setting a future date is what reopens a lapsed academy."
          value={periodEndsAt}
          onChange={(event) => setPeriodEndsAt(event.currentTarget.value)}
        />

        {apiError && (
          <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="alert">
            {apiError.message}
          </Alert>
        )}

        <Group justify="flex-end">
          <Button variant="default" onClick={close} disabled={assign.isPending}>
            Cancel
          </Button>
          <Button loading={assign.isPending} disabled={plan === null} onClick={submit}>
            Save and renew
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
