import {
  Badge,
  Button,
  Card,
  Container,
  Group,
  Pagination,
  SegmentedControl,
  Stack,
  Text,
} from '@mantine/core';
import { IconArticle, IconPlus } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useNavigate } from 'react-router';

import { EmptyState, ErrorState, LoadingState, PageHeader } from '@/shared/ui';

import { adminPostsQuery, useCreatePost, type PostFilters } from '../api/posts';
import { postState } from '../lib/posts';

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'draft', label: 'Drafts' },
  { value: 'published', label: 'Published' },
];

/** Every post on the academy's blog, drafts included, and the way to start one. */
export function PostsRoute() {
  const navigate = useNavigate();
  const [filters, setFilters] = useState<PostFilters>({ status: 'all', page: 1 });
  const { data, isPending, isError, error, refetch } = useQuery(adminPostsQuery(filters));
  const create = useCreatePost();

  const startOne = () => {
    create.mutate(
      { title: 'Untitled post' },
      { onSuccess: (post) => void navigate(`/admin/posts/${post.id}`) },
    );
  };

  return (
    <Container size="md" py="lg">
      <PageHeader
        title="Blog"
        description="Posts on your academy's public site. Only published ones are visible there."
        actions={
          <Button
            leftSection={<IconPlus size={16} />}
            loading={create.isPending}
            onClick={startOne}
          >
            New post
          </Button>
        }
      />

      <Stack gap="md">
        <SegmentedControl
          aria-label="Filter by status"
          data={FILTERS}
          value={filters.status}
          onChange={(value) =>
            setFilters({
              status: value === 'draft' || value === 'published' ? value : 'all',
              page: 1,
            })
          }
          style={{ alignSelf: 'flex-start' }}
        />

        {isPending ? <LoadingState rows={3} height={72} label="Loading posts" /> : null}
        {isError ? <ErrorState error={error} onRetry={() => void refetch()} /> : null}

        {data && data.data.length === 0 ? (
          filters.status === 'all' ? (
            <EmptyState
              icon={IconArticle}
              title="No posts yet"
              description="Write about what you teach, announce a new course, or tell the story of a student's work."
              action={{ label: 'Write the first one', onClick: startOne }}
            />
          ) : (
            <EmptyState
              icon={IconArticle}
              title="Nothing here"
              description="No posts match this filter."
            />
          )
        ) : null}

        {data && data.data.length > 0 ? (
          <Stack gap="xs">
            {data.data.map((post) => {
              const state = postState(post);

              return (
                <Card
                  key={post.id}
                  withBorder
                  padding="sm"
                  component={Link}
                  to={`/admin/posts/${post.id}`}
                >
                  <Group justify="space-between" gap="sm" wrap="nowrap">
                    <Stack gap={2} style={{ minWidth: 0 }}>
                      <Text fw={600} truncate>
                        {post.title}
                      </Text>
                      <Text size="sm" c="dimmed">
                        {post.published_at
                          ? `${post.is_scheduled ? 'Goes out' : 'Out since'} ${new Date(post.published_at).toLocaleString()}`
                          : `Edited ${new Date(post.updated_at).toLocaleString()}`}
                      </Text>
                    </Stack>
                    <Badge color={state.color} variant="light">
                      {state.label}
                    </Badge>
                  </Group>
                </Card>
              );
            })}

            {data.meta.last_page > 1 ? (
              <Group justify="center">
                <Pagination
                  value={filters.page}
                  onChange={(page) => setFilters((current) => ({ ...current, page }))}
                  total={data.meta.last_page}
                />
              </Group>
            ) : null}
          </Stack>
        ) : null}
      </Stack>
    </Container>
  );
}
