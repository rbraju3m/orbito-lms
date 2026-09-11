import {
  Alert,
  Button,
  Card,
  Container,
  Group,
  Modal,
  Pagination,
  Stack,
  Text,
  Textarea,
} from '@mantine/core';
import { zodResolver } from '@hookform/resolvers/zod';
import { IconAlertTriangle, IconReceiptRefund } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { Link } from 'react-router';

import { ApiError } from '@/shared/api/errors';
import { formatDateTime } from '@/shared/lib/datetime';
import { formatMinor } from '@/shared/lib/money';
import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import {
  refundReportsQuery,
  useResolveRefundReport,
  type RefundReport,
  type RefundReportItem,
} from '../api/refundReports';
import { resolveReportSchema, type ResolveReportValues } from '../refundReportSchema';

/**
 * Refunds the payment provider reported that the books could not take in on
 * their own (`order.refund`, docs/REFUNDS.md §6).
 *
 * Every word about what happened and what to check is the server's — the page
 * renders it and decides nothing. Resolving records who looked and what they
 * found; any fix itself goes through the order's refund dialog, where it is
 * checked like any other refund.
 */
export function RefundReportsRoute() {
  const [page, setPage] = useState(1);
  const { data, isPending, isError, error, refetch } = useQuery(refundReportsQuery(page));
  const [resolving, setResolving] = useState<RefundReport | null>(null);

  if (isPending) return <LoadingState rows={3} label="Loading refund reports" />;
  if (isError) return <ErrorState error={error} onRetry={() => void refetch()} />;

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Refund reports"
        description="Refunds your payment provider reported that could not be matched to the books on their own. Each needs a person to look."
      />

      {data.data.length === 0 ? (
        <EmptyState
          icon={IconReceiptRefund}
          title="Nothing needs you"
          description="Every refund your provider has reported is on its order. One that cannot be taken in on its own will appear here."
        />
      ) : (
        <Stack gap="sm">
          {data.data.map((report) => (
            <ReportCard key={report.id} report={report} onResolve={() => setResolving(report)} />
          ))}

          {data.meta.last_page > 1 ? (
            <Group justify="center">
              <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
            </Group>
          ) : null}
        </Stack>
      )}

      <ResolveModal report={resolving} onClose={() => setResolving(null)} />
    </Container>
  );
}

function ReportCard({ report, onResolve }: { report: RefundReport; onResolve: () => void }) {
  return (
    <Card withBorder>
      <Stack gap="sm">
        {report.items.map((item, index) => (
          <ReportItem
            key={`${item.provider_refund_id ?? 'refund'}-${index}`}
            report={report}
            item={item}
          />
        ))}

        <Group justify="space-between" gap="sm" wrap="wrap">
          <Text size="xs" c="dimmed">
            Received {formatDateTime(report.received_at)}
          </Text>
          <Group gap="xs">
            {report.order ? (
              <Button
                component={Link}
                to={`/orders/${report.order.id}`}
                variant="light"
                size="compact-sm"
              >
                Order {report.order.number}
              </Button>
            ) : null}
            <Button variant="subtle" size="compact-sm" onClick={onResolve}>
              Mark resolved
            </Button>
          </Group>
        </Group>
      </Stack>
    </Card>
  );
}

function ReportItem({ report, item }: { report: RefundReport; item: RefundReportItem }) {
  const amount =
    item.amount_minor !== null && item.currency !== null
      ? formatMinor(item.amount_minor, item.currency)
      : 'a refund';

  return (
    <Stack gap={4}>
      <Text fw={600}>{item.reason_label}</Text>
      {item.advice ? <Text size="sm">{item.advice}</Text> : null}
      <Text size="xs" c="dimmed">
        {report.gateway_label} reported {amount}
        {item.reported_status_label ? ` · ${item.reported_status_label}` : ''}
        {item.provider_refund_id ? ` · ${item.provider_refund_id}` : ''}
      </Text>
    </Stack>
  );
}

function ResolveModal({ report, onClose }: { report: RefundReport | null; onClose: () => void }) {
  const resolve = useResolveRefundReport();
  const {
    register,
    handleSubmit,
    reset,
    formState: { errors },
  } = useForm<ResolveReportValues>({
    resolver: zodResolver(resolveReportSchema),
    defaultValues: { note: '' },
  });

  const failure = resolve.error instanceof ApiError ? resolve.error.message : null;

  const close = () => {
    reset();
    resolve.reset();
    onClose();
  };

  const submit = handleSubmit((values) => {
    if (!report) return;

    const note = values.note.trim();
    resolve.mutate({ id: report.id, note: note === '' ? null : note }, { onSuccess: close });
  });

  return (
    <Modal opened={report !== null} onClose={close} title="Mark resolved" centered>
      <form onSubmit={(event) => void submit(event)}>
        <Stack gap="sm">
          <Text size="sm">
            This records that you have dealt with it. It changes no money and no access — make any
            refund from the order itself.
          </Text>

          <Textarea
            label="What did you do?"
            description="Optional. Whoever reads this report next will see it."
            autosize
            minRows={2}
            {...register('note')}
            error={errors.note?.message}
          />

          {failure ? (
            <Alert color="red" icon={<IconAlertTriangle size={16} />}>
              {failure}
            </Alert>
          ) : null}

          <Group justify="flex-end">
            <Button variant="subtle" onClick={close}>
              Cancel
            </Button>
            <Button type="submit" loading={resolve.isPending}>
              Resolve
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
