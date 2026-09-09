import { Alert, Anchor, Container } from '@mantine/core';
import { IconBuildingCommunity } from '@tabler/icons-react';
import { Link } from 'react-router';

import { useSession } from '@/features/auth/hooks/useSession';

/**
 * A platform operator who is inside no academy.
 *
 * `users.tenant_id` null means the central connection, where none of the
 * domain tables exist — so every product screen in the shell is empty or
 * errors. That is confusing in a way nothing on the screen explains, and an
 * operator who has just stepped out of an academy hits it immediately.
 *
 * Only ever shown to an operator. A member always belongs to an academy, so
 * for them this is unreachable rather than merely hidden.
 */
export function NoAcademyBanner() {
  const { session } = useSession();

  if (session?.is_platform_operator !== true) return null;
  if (session.academy !== null) return null;

  return (
    <Container size="xl" pt="sm">
      <Alert
        color="warning"
        icon={<IconBuildingCommunity size={18} />}
        title="You are not inside an academy"
        role="status"
      >
        Courses, learners and grading all live inside one, so those screens have nothing behind
        them until you step in.{' '}
        <Anchor component={Link} to="/platform/academies">
          Choose an academy
        </Anchor>
        .
      </Alert>
    </Container>
  );
}
