import { describe, expect, it } from 'vitest';

import type { CourseItem, CourseSection } from '../api/types';

import { applyDrag, formatDuration, sectionDomId, toReorderPayload } from './useCurriculumTree';

function item(ref: number, sectionId: number): CourseItem {
  return {
    id: `item-${ref}`,
    ref,
    section_id: sectionId,
    position: ref,
    type: 'lesson',
    type_label: 'Lesson',
    title: `Item ${ref}`,
    is_preview: false,
    is_published: true,
    is_completable: true,
    duration_seconds: 60,
    updated_at: null,
  };
}

function tree(): CourseSection[] {
  return [
    { id: 1, title: 'One', description: null, position: 0, items: [item(1, 1), item(2, 1)] },
    { id: 2, title: 'Two', description: null, position: 1, items: [item(3, 2), item(4, 2)] },
  ];
}

describe('toReorderPayload', () => {
  it('sends numeric refs in display order', () => {
    expect(toReorderPayload(tree())).toEqual({
      sections: [
        { id: 1, item_ids: [1, 2] },
        { id: 2, item_ids: [3, 4] },
      ],
    });
  });

  it('keeps an empty section in the payload', () => {
    const sections = tree();
    sections[1]!.items = [];

    // Omitting it would make the server reject the tree as a non-permutation.
    expect(toReorderPayload(sections).sections).toHaveLength(2);
    expect(toReorderPayload(sections).sections[1]!.item_ids).toEqual([]);
  });
});

describe('applyDrag', () => {
  it('reorders items inside a section', () => {
    const next = applyDrag(tree(), 'item-1', 'item-2');

    expect(next[0]!.items.map((i) => i.ref)).toEqual([2, 1]);
    expect(next[1]!.items.map((i) => i.ref)).toEqual([3, 4]);
  });

  it('moves an item into another section at the hovered position', () => {
    const next = applyDrag(tree(), 'item-1', 'item-4');

    expect(next[0]!.items.map((i) => i.ref)).toEqual([2]);
    expect(next[1]!.items.map((i) => i.ref)).toEqual([3, 1, 4]);
  });

  it('drops an item into an empty section', () => {
    const sections = tree();
    sections[1]!.items = [];

    // An empty section has no item to hover, so the section itself is the
    // droppable target.
    const next = applyDrag(sections, 'item-1', sectionDomId(2));

    expect(next[0]!.items.map((i) => i.ref)).toEqual([2]);
    expect(next[1]!.items.map((i) => i.ref)).toEqual([1]);
  });

  it('reorders whole sections', () => {
    const next = applyDrag(tree(), sectionDomId(1), sectionDomId(2));

    expect(next.map((s) => s.id)).toEqual([2, 1]);
  });

  it('never loses or duplicates an item', () => {
    const moves: Array<[string, string]> = [
      ['item-1', 'item-4'],
      ['item-3', 'item-2'],
      [sectionDomId(2), sectionDomId(1)],
    ];

    let sections = tree();
    for (const [from, to] of moves) sections = applyDrag(sections, from, to);

    const refs = sections.flatMap((s) => s.items.map((i) => i.ref)).sort();
    // The server rejects a non-permutation, so the client must never build one.
    expect(refs).toEqual([1, 2, 3, 4]);
  });

  it('returns the same tree when nothing moved', () => {
    const sections = tree();

    expect(applyDrag(sections, 'item-1', 'item-1')).toBe(sections);
    expect(applyDrag(sections, 'unknown', 'item-1')).toBe(sections);
  });
});

describe('formatDuration', () => {
  it('renders minutes, hours and an em dash for nothing', () => {
    expect(formatDuration(0)).toBe('—');
    expect(formatDuration(90)).toBe('2 min');
    expect(formatDuration(3600)).toBe('1h 0m');
    expect(formatDuration(5400)).toBe('1h 30m');
  });
});
