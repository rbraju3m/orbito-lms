import { arrayMove } from '@dnd-kit/sortable';

import type { CourseSection, ReorderPayload } from '../api/types';

/** The tree in the shape the reorder endpoint accepts. */
export function toReorderPayload(sections: CourseSection[]): ReorderPayload {
  return {
    sections: sections.map((section) => ({
      id: section.id,
      item_ids: section.items.map((item) => item.ref),
    })),
  };
}

export function findSectionOfItem(
  sections: CourseSection[],
  itemId: string,
): CourseSection | undefined {
  return sections.find((section) => section.items.some((item) => item.id === itemId));
}

/**
 * Applies a drag locally so the UI can update before the server confirms.
 *
 * Handles the three cases a builder needs: reorder inside a section, move to a
 * different section, and reorder the sections themselves.
 */
export function applyDrag(
  sections: CourseSection[],
  activeId: string,
  overId: string,
): CourseSection[] {
  // Dragging a section.
  const activeSectionIndex = sections.findIndex((s) => sectionDomId(s.id) === activeId);
  if (activeSectionIndex !== -1) {
    const overSectionIndex = sections.findIndex((s) => sectionDomId(s.id) === overId);
    if (overSectionIndex === -1 || activeSectionIndex === overSectionIndex) return sections;
    return arrayMove(sections, activeSectionIndex, overSectionIndex);
  }

  // Dragging an item.
  const from = findSectionOfItem(sections, activeId);
  if (!from) return sections;

  // `over` is either another item, or an empty section's droppable.
  const toBySection = sections.find((s) => sectionDomId(s.id) === overId);
  const to = toBySection ?? findSectionOfItem(sections, overId);
  if (!to) return sections;

  const item = from.items.find((i) => i.id === activeId);
  if (!item) return sections;

  if (from.id === to.id && !toBySection) {
    const oldIndex = from.items.findIndex((i) => i.id === activeId);
    const newIndex = from.items.findIndex((i) => i.id === overId);
    if (oldIndex === newIndex) return sections;

    return sections.map((section) =>
      section.id === from.id
        ? { ...section, items: arrayMove(section.items, oldIndex, newIndex) }
        : section,
    );
  }

  const insertAt = toBySection ? to.items.length : to.items.findIndex((i) => i.id === overId);

  return sections.map((section) => {
    if (section.id === from.id) {
      return { ...section, items: section.items.filter((i) => i.id !== activeId) };
    }
    if (section.id === to.id) {
      const next = section.items.filter((i) => i.id !== activeId);
      next.splice(insertAt < 0 ? next.length : insertAt, 0, item);
      return { ...section, items: next };
    }
    return section;
  });
}

/** dnd-kit ids must be unique across the whole context, hence the prefix. */
export function sectionDomId(id: number): string {
  return `section-${id}`;
}

export function formatDuration(seconds: number): string {
  if (seconds <= 0) return '—';
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes} min`;
  const hours = Math.floor(minutes / 60);
  return `${hours}h ${minutes % 60}m`;
}
