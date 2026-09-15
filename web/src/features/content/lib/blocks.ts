import type { EditableBlock } from '../api/pages';
import type { BlockType, PageBlock } from '../api/pageTypes';

/**
 * The block palette, in the order the "Add block" menu offers it. The server's
 * `BlockType` is the closed set; this is only how the builder names it.
 */
export const BLOCK_TYPES: ReadonlyArray<{ type: BlockType; label: string; description: string }> = [
  { type: 'heading', label: 'Heading', description: 'A section title' },
  { type: 'text', label: 'Text', description: 'Paragraphs, lists and links (HTML)' },
  { type: 'image', label: 'Image', description: 'A picture with an optional caption' },
  { type: 'button', label: 'Button', description: 'A link to a page here or elsewhere' },
  { type: 'courses', label: 'Courses', description: 'Cards for courses you pick' },
  { type: 'webinars', label: 'Upcoming events', description: 'The next webinars' },
  { type: 'posts', label: 'Latest posts', description: 'The newest blog posts' },
  { type: 'lead_form', label: 'Stay-in-touch form', description: 'Collect email addresses' },
];

export function blockLabel(type: BlockType): string {
  return BLOCK_TYPES.find((option) => option.type === type)?.label ?? type;
}

/** A new block with props the server will accept once the author fills them in. */
export function newBlock(type: BlockType, id: string = crypto.randomUUID()): EditableBlock {
  const props: Record<BlockType, Record<string, unknown>> = {
    heading: { text: '', level: 2 },
    text: { html: '' },
    image: { media_ref: 0, alt: null, caption: null },
    button: { label: '', url: '/' },
    courses: { title: null, course_ids: [] },
    webinars: { title: null, limit: 3 },
    posts: { title: null, limit: 3 },
    lead_form: { title: null, description: null },
  };

  return { id, type, props: props[type] };
}

/** The saved blocks as the builder edits them — the server's `data` left behind. */
export function toEditable(blocks: PageBlock[]): EditableBlock[] {
  return blocks.map((block) => ({ id: block.id, type: block.type, props: { ...block.props } }));
}

/** One line for a block's row in the builder list. */
export function blockSummary(block: EditableBlock): string {
  const text = (key: string) => {
    const value = block.props[key];
    return typeof value === 'string' ? value.trim() : '';
  };

  switch (block.type) {
    case 'heading':
      return text('text') || 'Empty heading';
    case 'text': {
      const plain = text('html')
        .replace(/<[^>]*>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
      return plain === '' ? 'Empty text' : plain.slice(0, 80);
    }
    case 'image':
      return text('alt') || (block.props['media_ref'] ? 'Image' : 'No image chosen');
    case 'button':
      return text('label') ? `${text('label')} → ${text('url')}` : 'Button with no label';
    case 'courses': {
      const ids = block.props['course_ids'];
      const count = Array.isArray(ids) ? ids.length : 0;
      return count === 1 ? '1 course' : `${count} courses`;
    }
    case 'webinars':
    case 'posts':
      return text('title') || `Up to ${String(block.props['limit'] ?? 3)}`;
    case 'lead_form':
      return text('title') || 'Stay in touch';
  }
}
