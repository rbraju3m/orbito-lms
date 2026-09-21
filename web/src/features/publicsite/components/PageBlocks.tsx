import { Anchor, Box, Button, Card, Image, SimpleGrid, Stack, Text, Title } from '@mantine/core';
import { Link } from 'react-router';

import { CourseCard } from '@/features/catalog/components/CourseCard';
import type { PageBlock } from '@/features/content/api/pageTypes';

import { LeadCaptureForm } from './LeadCaptureForm';

export interface PageBlocksProps {
  academy: string;
  blocks: PageBlock[];
}

/**
 * A built page's blocks, as a visitor sees them — and as the builder's
 * preview shows them. ONE renderer for both, so the preview cannot drift from
 * the public page (docs/PAGES.md §4).
 *
 * Every link stays inside the academy's site. A block that points at nothing a
 * stranger could open — a course grid whose courses all went back to draft,
 * an events block with no upcoming events — renders nothing rather than an
 * empty frame.
 */
export function PageBlocks({ academy, blocks }: PageBlocksProps) {
  return (
    <Stack gap="xl">
      {blocks.map((block) => (
        <BlockView key={block.id} academy={academy} block={block} />
      ))}
    </Stack>
  );
}

function BlockView({ academy, block }: { academy: string; block: PageBlock }) {
  switch (block.type) {
    case 'heading':
      return <Title order={block.props.level}>{block.props.text}</Title>;

    case 'text':
      // Sanitised by the server when the page was saved (RichTextSanitizer).
      return (
        <Box className="orbito-prose" dangerouslySetInnerHTML={{ __html: block.props.html }} />
      );

    case 'image':
      return block.data?.url ? (
        <Box component="figure" m={0}>
          <Image src={block.data.url} alt={block.props.alt ?? ''} radius="md" />
          {block.props.caption ? (
            <Text component="figcaption" size="sm" c="dimmed" mt="xs">
              {block.props.caption}
            </Text>
          ) : null}
        </Box>
      ) : null;

    case 'button':
      return block.props.url.startsWith('/') ? (
        <Button component={Link} to={block.props.url} style={{ alignSelf: 'flex-start' }}>
          {block.props.label}
        </Button>
      ) : (
        <Button
          component="a"
          href={block.props.url}
          rel="noopener noreferrer"
          style={{ alignSelf: 'flex-start' }}
        >
          {block.props.label}
        </Button>
      );

    case 'courses':
      return block.data && block.data.length > 0 ? (
        <Stack gap="md">
          {block.props.title ? (
            <Title order={2} size="h3">
              {block.props.title}
            </Title>
          ) : null}
          <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
            {block.data.map((course) => (
              <CourseCard
                key={course.id}
                course={course}
                to={`/a/${academy}/courses/${course.slug}`}
                headingOrder={3}
              />
            ))}
          </SimpleGrid>
        </Stack>
      ) : null;

    case 'webinars':
      return block.data && block.data.length > 0 ? (
        <Stack gap="md">
          {block.props.title ? (
            <Title order={2} size="h3">
              {block.props.title}
            </Title>
          ) : null}
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            {block.data.map((webinar) => (
              <Card key={webinar.id} withBorder padding="md">
                <Stack gap={4}>
                  <Anchor component={Link} to={`/a/${academy}/webinars/${webinar.slug}`} fw={600}>
                    {webinar.title}
                  </Anchor>
                  {webinar.session ? (
                    <Text size="sm" c="dimmed">
                      {new Date(webinar.session.starts_at).toLocaleString()} (
                      {webinar.session.timezone})
                    </Text>
                  ) : null}
                </Stack>
              </Card>
            ))}
          </SimpleGrid>
        </Stack>
      ) : null;

    case 'posts':
      return block.data && block.data.length > 0 ? (
        <Stack gap="md">
          {block.props.title ? (
            <Title order={2} size="h3">
              {block.props.title}
            </Title>
          ) : null}
          <Stack gap="sm">
            {block.data.map((post) => (
              <Stack key={post.id} gap={2}>
                <Anchor component={Link} to={`/a/${academy}/blog/${post.slug}`} fw={600}>
                  {post.title}
                </Anchor>
                {post.excerpt ? <Text size="sm">{post.excerpt}</Text> : null}
              </Stack>
            ))}
          </Stack>
        </Stack>
      ) : null;

    case 'lead_form':
      return (
        <LeadCaptureForm
          academy={academy}
          source="site"
          {...(block.props.title ? { title: block.props.title } : {})}
          {...(block.props.description ? { description: block.props.description } : {})}
        />
      );
  }
}
