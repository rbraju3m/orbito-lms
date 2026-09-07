import { Container } from '@mantine/core';

import { EmptyState, PageHeader } from '@/shared/ui';

export function MyCoursesRoute() {
  return (
    <Container size="lg" py="lg">
      <PageHeader title="My learning" description="Courses you are enrolled in." />
      <EmptyState
        title="You're not enrolled in anything yet"
        description="Enrolment arrives in Phase 6, once courses and the player exist."
      />
    </Container>
  );
}
