import { Alert, Badge, Button, Group, Modal, Stack, Table, Text, Textarea } from '@mantine/core';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useState } from 'react';

import { ApiError } from '@/shared/api/errors';

import { useBulkEnrol } from '../api/queries';
import type { BulkEnrollRow } from '../api/types';

/** Matches the server's cap. Above this the answer is an import, not a bigger box. */
const MAX_ROWS = 200;

const ROW_COLOUR: Record<BulkEnrollRow['status'], string> = {
  enrolled: 'green',
  skipped: 'yellow',
  not_found: 'red',
};

function parseEmails(raw: string): string[] {
  return [
    ...new Set(
      raw
        .split(/[\s,;]+/)
        .map((value) => value.trim().toLowerCase())
        .filter(Boolean),
    ),
  ];
}

/**
 * One box, one paste, a verdict per row.
 *
 * The server enrols the good rows even when others fail, so this reports each
 * outcome rather than a single success or failure — the useful answer is which
 * three addresses were typos.
 */
export function EnrolStudentsModal({
  courseId,
  opened,
  onClose,
}: {
  courseId: string;
  opened: boolean;
  onClose: () => void;
}) {
  const [raw, setRaw] = useState('');
  const bulk = useBulkEnrol(courseId);

  const emails = parseEmails(raw);
  const tooMany = emails.length > MAX_ROWS;
  const result = bulk.data;
  const apiError = bulk.error instanceof ApiError ? bulk.error : null;

  const close = () => {
    setRaw('');
    bulk.reset();
    onClose();
  };

  return (
    <Modal opened={opened} onClose={close} title="Enrol students" size="lg" centered>
      <Stack gap="md">
        {!result && (
          <>
            <Textarea
              label="Email addresses"
              description="One per line, or separated by commas. Each must already have an account."
              placeholder="ada@example.com&#10;grace@example.com"
              autosize
              minRows={5}
              maxRows={12}
              value={raw}
              onChange={(event) => setRaw(event.currentTarget.value)}
            />

            <Group justify="space-between">
              <Text c={tooMany ? 'red' : 'dimmed'} size="sm">
                {emails.length} address{emails.length === 1 ? '' : 'es'}
                {tooMany ? ` — at most ${MAX_ROWS} at a time` : ''}
              </Text>
            </Group>

            {apiError && (
              <Alert
                color={apiError.isBillingBlocked ? 'yellow' : 'red'}
                icon={<IconAlertTriangle size={16} />}
              >
                {apiError.message}
              </Alert>
            )}

            <Group justify="flex-end">
              <Button variant="default" onClick={close} disabled={bulk.isPending}>
                Cancel
              </Button>
              <Button
                loading={bulk.isPending}
                disabled={emails.length === 0 || tooMany}
                onClick={() => bulk.mutate(emails)}
              >
                Enrol {emails.length > 0 ? emails.length : ''}
              </Button>
            </Group>
          </>
        )}

        {result && (
          <>
            <Group gap="xs">
              <Badge color="green" variant="light">
                {result.summary.enrolled} enrolled
              </Badge>
              <Badge color="yellow" variant="light">
                {result.summary.skipped} skipped
              </Badge>
              <Badge color="red" variant="light">
                {result.summary.not_found} not found
              </Badge>
            </Group>

            <Table.ScrollContainer minWidth={420} mah={320}>
              <Table verticalSpacing="xs" fz="sm">
                <Table.Tbody>
                  {result.results.map((row) => (
                    <Table.Tr key={row.email}>
                      <Table.Td>{row.email}</Table.Td>
                      <Table.Td w={110}>
                        <Badge color={ROW_COLOUR[row.status]} variant="light" size="sm">
                          {row.status.replace('_', ' ')}
                        </Badge>
                      </Table.Td>
                      <Table.Td>
                        <Text c="dimmed" size="xs">
                          {row.message ?? ''}
                        </Text>
                      </Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </Table.ScrollContainer>

            <Group justify="flex-end">
              <Button
                variant="default"
                onClick={() => {
                  setRaw('');
                  bulk.reset();
                }}
              >
                Enrol more
              </Button>
              <Button onClick={close}>Done</Button>
            </Group>
          </>
        )}
      </Stack>
    </Modal>
  );
}
