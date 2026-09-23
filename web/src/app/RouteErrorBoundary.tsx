import { Container } from '@mantine/core';
import { isRouteErrorResponse, useRouteError } from 'react-router';

import { t } from '@/shared/i18n';
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
          title={t('shell.not_found.title', 'Page not found')}
          error={
            new Error(
              t(
                'shell.not_found.body',
                'That page does not exist, or you do not have access to it.',
              ),
            )
          }
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
