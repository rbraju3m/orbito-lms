import { Container } from '@mantine/core';
import { isRouteErrorResponse, useRouteError } from 'react-router';

import { ErrorState } from '@/shared/ui';

/**
 * Catches render and loader failures for a route subtree so one broken page
 * never blanks the whole app.
 */
export function RouteErrorBoundary() {
  const error = useRouteError();

  if (isRouteErrorResponse(error) && error.status === 404) {
    return (
      <Container size="md" py="xl">
        <ErrorState
          title="Page not found"
          error={new Error('That page does not exist, or you do not have access to it.')}
          onRetry={() => {
            window.location.href = '/';
          }}
        />
      </Container>
    );
  }

  return (
    <Container size="md" py="xl">
      <ErrorState error={error} onRetry={() => window.location.reload()} />
    </Container>
  );
}
