import { Anchor, Box, Card, Center, Container, Group, Stack, Text, Title } from '@mantine/core';
import { Link, Outlet } from 'react-router';

import { ThemeToggle } from '@/shared/ui';

/** Focused shell for sign-in, registration and password flows. */
export function AuthLayout() {
  return (
    <Box mih="100dvh">
      <Group justify="space-between" px="md" py="sm">
        <Anchor component={Link} to="/" underline="never">
          <Title order={4}>Orbito</Title>
        </Anchor>
        <ThemeToggle />
      </Group>

      <Center px="md" pb="xl">
        <Container size={440} w="100%">
          <Card padding="xl">
            <Outlet />
          </Card>

          <Stack align="center" mt="md">
            <Text size="xs" c="dimmed">
              Orbito LMS
            </Text>
          </Stack>
        </Container>
      </Center>
    </Box>
  );
}
