import { Anchor, Card, Group, Image, Pagination, Stack, Text, Title } from '@mantine/core';
import { IconArticle } from '@tabler/icons-react';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useParams } from 'react-router';

import { EmptyState, ErrorState, LoadingState } from '@/shared/ui';
import { formatDate } from '@/shared/lib/datetime';

import { publicPostsQuery } from '../api/blog';
import { publicAcademyQuery } from '../api/queries';

/**
 * The academy's blog list, readable with no account.
 *
 * The page sets its own `<title>` and description, which React 19 hoists into
 * the document head. These pages render in the browser — a decision taken in
 * Phase 16 (docs/BLOG.md §5) — so a crawler that does not run JavaScript sees
 * none of it; the tags still name the tab and the shared link for everybody
 * else.
 */
export function BlogIndexRoute() {
  const { academy = '' } = useParams();
  const [page, setPage] = useState(1);

  const academyQuery = useQuery(publicAcademyQuery(academy));
  const { data, isPending, isError, error, refetch } = useQuery(publicPostsQuery(academy, page));

  const academyName = academyQuery.data?.name;

  return (
    <Stack gap="lg" p="md" maw={900} mx="auto">
      {academyName ? (
        <>
          <title>{`Blog — ${academyName}`}</title>
          <meta name="description" content={`News and writing from ${academyName}.`} />
        </>
      ) : null}

      <Title order={1}>Blog</Title>

      {isPending ? <LoadingState rows={3} height={120} label="Loading posts" /> : null}

      {isError ? (
        <ErrorState
          error={error}
          title="The blog could not be loaded"
          onRetry={() => void refetch()}
        />
      ) : null}

      {data && data.data.length === 0 ? (
        <EmptyState
          icon={IconArticle}
          title="No posts yet"
          description="Nothing has been published here so far."
        />
      ) : null}

      {data && data.data.length > 0 ? (
        <Stack gap="md">
          {data.data.map((post) => (
            <Card key={post.id} withBorder padding="lg" component="article">
              <Stack gap="sm">
                {post.cover_url ? (
                  <Image src={post.cover_url} alt="" radius="sm" mah={240} fit="cover" />
                ) : null}
                <Anchor component={Link} to={`/a/${academy}/blog/${post.slug}`} fw={700} size="xl">
                  {post.title}
                </Anchor>
                {post.excerpt ? <Text>{post.excerpt}</Text> : null}
                <Group gap="xs">
                  {post.published_at ? (
                    <Text size="sm" c="dimmed">
                      {formatDate(post.published_at)}
                    </Text>
                  ) : null}
                  <Text size="sm" c="dimmed">
                    · {post.reading_minutes} min read
                  </Text>
                  {post.author ? (
                    <Text size="sm" c="dimmed">
                      · {post.author.name}
                    </Text>
                  ) : null}
                </Group>
              </Stack>
            </Card>
          ))}

          {data.meta.last_page > 1 ? (
            <Group justify="center">
              <Pagination value={page} onChange={setPage} total={data.meta.last_page} />
            </Group>
          ) : null}
        </Stack>
      ) : null}
    </Stack>
  );
}
