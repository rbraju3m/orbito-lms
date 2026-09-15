import { describe, expect, it } from 'vitest';

import type { PageBlock } from '../api/pageTypes';
import { blockSummary, newBlock, toEditable } from './blocks';

describe('newBlock', () => {
  it('starts each type with the props its server rules expect', () => {
    expect(newBlock('heading', 'a')).toEqual({
      id: 'a',
      type: 'heading',
      props: { text: '', level: 2 },
    });
    expect(newBlock('posts', 'b').props).toEqual({ title: null, limit: 3 });
    expect(newBlock('courses', 'c').props).toEqual({ title: null, course_ids: [] });
  });
});

describe('toEditable', () => {
  it('leaves the server-resolved data behind, so it is never sent back', () => {
    const saved: PageBlock[] = [
      {
        id: 'x',
        type: 'image',
        props: { media_ref: 4, alt: 'Studio', caption: null },
        data: { url: 'https://cdn/x.webp' },
      },
    ];

    expect(toEditable(saved)).toEqual([
      { id: 'x', type: 'image', props: { media_ref: 4, alt: 'Studio', caption: null } },
    ]);
  });
});

describe('blockSummary', () => {
  it('describes a block in one line', () => {
    expect(blockSummary({ id: '1', type: 'heading', props: { text: 'About us', level: 2 } })).toBe(
      'About us',
    );
    expect(
      blockSummary({
        id: '2',
        type: 'text',
        props: { html: '<p>Paint <strong>every</strong> day.</p>' },
      }),
    ).toBe('Paint every day.');
    expect(
      blockSummary({ id: '3', type: 'courses', props: { title: null, course_ids: ['a', 'b'] } }),
    ).toBe('2 courses');
    expect(blockSummary({ id: '4', type: 'button', props: { label: '', url: '/' } })).toBe(
      'Button with no label',
    );
  });
});
