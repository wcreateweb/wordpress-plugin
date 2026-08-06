import { Page } from '@playwright/test';
import { clearMediaLibrary, enableCompressionSizes, newPost, setAPIKey, setCompressionTiming, uploadMedia } from './utils';

/**
 * Builds a page rendering one image of every row state the admin-bar Images panel
 * can produce, and sets it as the site's front page.
 *
 * States (see docs/domain-model.md):
 *   optimizable  - uploaded JPEG, not compressed, rendered with a real srcset
 *   optimized    - uploaded JPEG, compressed via the mock API
 *   unsupported  - uploaded GIF; file_type_allowed() accepts only jpeg/png/webp
 *   unresolved   - an off-site URL, and a file under uploads with no attachment record
 *
 * Two of the images carry a wp-image-{id} class (editor-inserted content) and one is
 * rendered through wp_get_attachment_image() via the [tiny_theme_image] shortcode, which
 * emits no such class - the case that matters, since wp_get_attachment_image() is what
 * featured images and theme templates use and it never sets wp-image-{id}.
 *
 * Requires the mock TinyPNG API to be running (bin/run-mocks), otherwise the optimized
 * row cannot be produced.
 */

export const OFFSITE_IMAGE_URL = 'https://tinypng.com/images/panda-happy.png';

export interface PageScanFixture {
  /** ID of the published post, which is also the site's front page. */
  postID: string;
  /** Front-end URL of the fixture page. */
  url: string;
  optimizable: { attachmentID: string; imageURL: string };
  optimized: { attachmentID: string; imageURL: string };
  unsupported: { attachmentID: string; imageURL: string };
  /** The two unresolved cases: an off-site host and an orphan file under uploads. */
  unresolved: { offsiteURL: string; orphanURL: string };
}

/**
 * Creates a file in the uploads directory with no attachment record behind it.
 * Backed by the create_orphan_upload helper in test/fixtures/class-tiny-config.php.
 */
async function createOrphanUpload(page: Page): Promise<string> {
  const response = await page.request.post('/wp-admin/admin-ajax.php', {
    form: { action: 'create_orphan_upload' },
  });

  const body = await response.json();
  if (!body.success) {
    throw new Error(`could not create orphan upload: ${body.data?.message ?? 'unknown error'}`);
  }

  return body.data.url;
}

export async function createPageScanFixture(page: Page, WPVersion: number): Promise<PageScanFixture> {
  await clearMediaLibrary(page);
  await setAPIKey(page, 'JPG123');
  await enableCompressionSizes(page, ['0', 'thumbnail', 'medium', 'medium_large', 'large'], false);

  // Uploaded while compression is off, so it stays uncompressed.
  await setCompressionTiming(page, 'manual');
  const optimizable = await uploadMedia(page, 'input-example.jpg');
  const unsupported = await uploadMedia(page, 'input-example.gif');

  /*
   * Compressed on upload by the mock API. Deliberately a JPEG: the mock is keyed on the
   * API key, not the uploaded file, so JPG123 always returns a JPEG response and serves
   * output-example.jpg. Uploading a WebP here would write JPEG bytes into a .webp file and
   * record output.type as image/jpeg, which would make the panel's format column lie.
   */
  await setCompressionTiming(page, 'auto');
  const optimized = await uploadMedia(page, 'input-copyright.jpg');

  const orphanURL = await createOrphanUpload(page);

  const content = [
    // Editor-inserted: carries wp-image-{id}, so wp_filter_content_tags() adds srcset.
    `<figure class="wp-block-image size-large"><img src="${optimizable.imageURL}" alt="optimizable" class="wp-image-${optimizable.attachmentID}"/></figure>`,
    `<figure class="wp-block-image size-large"><img src="${unsupported.imageURL}" alt="unsupported" class="wp-image-${unsupported.attachmentID}"/></figure>`,
    // Theme-rendered: real srcset, no wp-image-{id}.
    `[tiny_theme_image id="${optimized.attachmentID}" size="large"]`,
    // Neither resolves to an attachment.
    `<figure><img src="${OFFSITE_IMAGE_URL}" alt="offsite"/></figure>`,
    `<figure><img src="${orphanURL}" alt="orphan"/></figure>`,
  ].join('\n');

  const postID = await newPost(page, { title: 'Page scan fixture', content }, WPVersion);

  return {
    postID,
    url: `/?p=${postID}`,
    optimizable,
    optimized,
    unsupported,
    unresolved: { offsiteURL: OFFSITE_IMAGE_URL, orphanURL },
  };
}
