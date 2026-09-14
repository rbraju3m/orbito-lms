import { Anchor, Box, Group, Image, Stack, Text, Title } from '@mantine/core';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';

import { ErrorState, LoadingState } from '@/shared/ui';

import { publicPostQuery } from '../api/blog';
import { publicAcademyQuery } from '../api/queries';

/**
 * One post, to a stranger.
 *
 * The body is rendered as HTML because the server sanitised it when it was
 * SAVED (`RichTextSanitizer`, § Patterns established in Phase 6) — there is no
 * second pass here, and there must not need to be.
 *
 * `<title>`, the description and the Open Graph tags are set from the post
 * (search title and description first, then the title and excerpt), and React
 * 19 hoists them into the head. Client-rendered by decision (docs/BLOG.md §5):
 * a crawler that runs no JavaScript will not see them.
 */
export function BlogPostRoute() {
  const { academy = '', slug = '' } = useParams();

  const academyQuery = useQuery(publicAcademyQuery(academy));
  const { data, isPending, isError, error } = useQuery(publicPostQuery(academy, slug));

  if (isPending) {
    return <LoadingState label="Loading post" rows={5} />;
  }

  if (isError) {
    return <ErrorState error={error} title="This post is not available" />;
  }

  const headline = data.seo_title ?? data.title;
  const description = data.seo_description ?? data.excerpt ?? '';
  const academyName = academyQuery.data?.name;

  return (
    <Stack gap="lg" p="md" maw={760} mx="auto" component="article">
      <title>{academyName ? `${headline} — ${academyName}` : headline}</title>
      {description ? <meta name="description" content={description} /> : null}
      <meta property="og:title" content={headline} />
      {description ? <meta property="og:description" content={description} /> : null}
      {data.cover_url ? <meta property="og:image" content={data.cover_url} /> : null}

      <Anchor component={Link} to={`/a/${academy}/blog`} size="sm">
        ← All posts
      </Anchor>

      <Stack gap="xs">
        <Title order={1}>{data.title}</Title>
        <Group gap="xs">
          {data.published_at ? (
            <Text c="dimmed">{new Date(data.published_at).toLocaleDateString()}</Text>
          ) : null}
          <Text c="dimmed">· {data.reading_minutes} min read</Text>
          {data.author ? <Text c="dimmed">· {data.author.name}</Text> : null}
        </Group>
      </Stack>

      {data.cover_url ? (
        <Image src={data.cover_url} alt="" radius="md" mah={420} fit="cover" />
      ) : null}

      {data.body ? (
        <Box className="orbito-prose" dangerouslySetInnerHTML={{ __html: data.body }} />
      ) : null}
    </Stack>
  );
}
