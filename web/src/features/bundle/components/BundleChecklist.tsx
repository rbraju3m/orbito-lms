import { Alert, List, Stack, Text, ThemeIcon } from '@mantine/core';
import { IconAlertTriangle, IconCheck, IconInfoCircle } from '@tabler/icons-react';

import type { BundleCheck } from '../api/types';

/**
 * The publish checklist, rendered from the server's own rules.
 *
 * The list comes back with the bundle, so the button and the answer cannot
 * disagree — the same contract `PublishChecklist` has held since Phase 4.
 * Blocking failures come first because they are the only ones that stop
 * anything; the advisory ones are worth saying and not worth leading with.
 */
export function BundleChecklist({ checks }: { checks: BundleCheck[] }) {
  const blocking = checks.filter((check) => check.blocking && !check.passed);
  const advisory = checks.filter((check) => !check.blocking && !check.passed);

  if (blocking.length === 0 && advisory.length === 0) {
    return (
      <Alert color="success" icon={<IconCheck size={16} />} role="note">
        Ready to publish.
      </Alert>
    );
  }

  return (
    <Stack gap="sm">
      {blocking.length > 0 ? (
        <Alert color="danger" icon={<IconAlertTriangle size={16} />} role="note">
          <Stack gap={6}>
            <Text size="sm" fw={600}>
              Before this can be published
            </Text>
            <List size="sm" spacing={4}>
              {blocking.map((check) => (
                <List.Item key={check.code}>{check.message}</List.Item>
              ))}
            </List>
          </Stack>
        </Alert>
      ) : null}

      {advisory.length > 0 ? (
        <Alert color="gray" variant="light" icon={<IconInfoCircle size={16} />} role="note">
          <Stack gap={6}>
            <Text size="sm" fw={600}>
              Worth doing
            </Text>
            <List
              size="sm"
              spacing={4}
              icon={
                <ThemeIcon size={14} radius="xl" color="gray" variant="light">
                  <IconInfoCircle size={10} />
                </ThemeIcon>
              }
            >
              {advisory.map((check) => (
                <List.Item key={check.code}>{check.message}</List.Item>
              ))}
            </List>
          </Stack>
        </Alert>
      ) : null}
    </Stack>
  );
}
