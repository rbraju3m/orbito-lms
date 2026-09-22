import type { Bundle } from '../api/types';

export interface BundleOwnership {
  /** Everything in the bundle — courses and downloads together. */
  total: number;
  owned: number;
  /** What buying it would actually add. Zero means there is nothing to sell them. */
  newToThem: number;
  ownsCourse: (ref: number) => boolean;
  ownsDownload: (ref: number) => boolean;
}

/**
 * What the reader already has of a bundle, from the ids the SERVER reported.
 *
 * Partial overlap does not block the sale (docs/BUNDLES.md §1), so the page
 * needs one honest count of what is new — across courses AND downloads, or a
 * buyer holding every course would be told there is nothing left while a
 * download they lack is still in it.
 */
export function bundleOwnership(bundle: Bundle): BundleOwnership {
  const courses = new Set(bundle.owned_course_ids ?? []);
  const downloads = new Set(bundle.owned_download_ids ?? []);

  const courseRefs = (bundle.courses ?? []).map((course) => course.ref);
  const downloadRefs = (bundle.downloads ?? []).map((download) => download.ref);

  const owned =
    courseRefs.filter((ref) => courses.has(ref)).length +
    downloadRefs.filter((ref) => downloads.has(ref)).length;
  const total = courseRefs.length + downloadRefs.length;

  return {
    total,
    owned,
    newToThem: total - owned,
    ownsCourse: (ref) => courses.has(ref),
    ownsDownload: (ref) => downloads.has(ref),
  };
}

/** "2 courses and 1 download", for a heading or a count. */
export function describeContents(courses: number, downloads: number): string {
  const parts: string[] = [];

  if (courses > 0) parts.push(`${courses} ${courses === 1 ? 'course' : 'courses'}`);
  if (downloads > 0) parts.push(`${downloads} ${downloads === 1 ? 'download' : 'downloads'}`);

  return parts.length > 0 ? parts.join(' and ') : 'Nothing yet';
}
