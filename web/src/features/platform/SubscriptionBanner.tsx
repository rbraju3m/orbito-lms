import { Alert, Container } from '@mantine/core';
import { IconAlertTriangle } from '@tabler/icons-react';

import { useSubscriptionLapsed } from './useSubscriptionLapsed';

/**
 * Shown app-wide once a write has been refused with 402.
 *
 * Not dismissible: nothing will save until the subscription is renewed, and a
 * banner the user can close is one they will close and then wonder why their
 * work vanishes. It disappears by itself the moment a write succeeds.
 */
export function SubscriptionBanner() {
  const message = useSubscriptionLapsed((state) => state.message);

  if (message === null) return null;

  return (
    <Container size="xl" pt="sm">
      <Alert
        color="yellow"
        icon={<IconAlertTriangle size={18} />}
        title="This academy cannot save changes"
        role="alert"
      >
        {message} Everything here is still readable and exportable.
      </Alert>
    </Container>
  );
}
