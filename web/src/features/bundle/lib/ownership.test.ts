import { describe, expect, it } from 'vitest';

import type { Bundle } from '../api/types';
import { bundleOwnership, describeContents } from './ownership';

function bundle(overrides: Partial<Bundle>): Bundle {
  return {
    id: 'b',
    slug: 'b',
    title: 'B',
    subtitle: null,
    description: null,
    status: 'published',
    status_label: 'Published',
    published_at: null,
    ...overrides,
  };
}

const courses = [{ ref: 1 }, { ref: 2 }] as Bundle['courses'];
const downloads = [{ ref: 1 }] as Bundle['downloads'];

describe('bundleOwnership', () => {
  /* A course and a download can share a numeric id; owning one is not owning the other. */
  it('keeps course and download ids apart', () => {
    const ownership = bundleOwnership(
      bundle({ courses, downloads, owned_course_ids: [1], owned_download_ids: [] }),
    );

    expect(ownership.ownsCourse(1)).toBe(true);
    expect(ownership.ownsDownload(1)).toBe(false);
    expect(ownership).toMatchObject({ total: 3, owned: 1, newToThem: 2 });
  });

  it('counts only ids that are actually in the bundle', () => {
    const ownership = bundleOwnership(bundle({ courses, owned_course_ids: [1, 99] }));

    expect(ownership).toMatchObject({ total: 2, owned: 1, newToThem: 1 });
  });
});

describe('describeContents', () => {
  it('names each kind, singular and plural', () => {
    expect(describeContents(2, 1)).toBe('2 courses and 1 download');
    expect(describeContents(1, 0)).toBe('1 course');
    expect(describeContents(0, 3)).toBe('3 downloads');
    expect(describeContents(0, 0)).toBe('Nothing yet');
  });
});
