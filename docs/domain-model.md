# Domain model

Shared vocabulary for the plugin. These names are load-bearing: they appear in class
names, method names, array keys and UI strings. Change them here first, then in code.

## Existing terms (pre-existing, recorded for collision safety)

| Term | Means | Where |
|---|---|---|
| **image** | An *attachment* — a row in the media library. | `Tiny_Image` |
| **size** | One registered size of an attachment. | `Tiny_Image_Size` |
| **`ORIGINAL`** | Integer `0`. The `-scaled` file when WordPress scaled the upload, **not** the uploaded original. | `Tiny_Image::ORIGINAL` |
| **`ORIGINAL_UNSCALED`** | Plugin-invented key with no counterpart in WP's `sizes` array. | `Tiny_Image::ORIGINAL_UNSCALED` |

**"Image" means attachment.** Nothing else may be called an image — in particular not an
`<img>` element on a rendered page. See *reference* below.

**One scoped exception, decided deliberately.** The Images panel's headline —
*"5 images on this page"* — counts **every row**, including `unresolved` ones that are not
attachments at all. `image` stays reserved for attachments in code, in array keys and in
row-level copy; the headline is the single place it counts what the page shows.

The reason is that the headline's number has to be verifiable by looking at the list beneath
it. Counting only attachments would print `3` above five visible rows, which reads as a bug
and costs more trust than the vocabulary slip does. The headline asserts nothing about any
individual file — each row's state does that, and `unresolved` still says plainly that the
file is not in the library. Settled in
[WF-17](../.wayfinder/tickets/WF-17-a-page-level-count-in-the-panel.md); if the headline ever
stops being a bare count, revisit this.

## Page scan

The admin-bar feature that scans the current front-end page for images and offers to
compress the sizes that page uses.

| Term | Means |
|---|---|
| **reference** | One `<img>` or `<picture>` element on the page — one place the page refers to an image. Carries its `src`, its `srcset` candidates and its `<source>` URLs as a single unit. |
| **referenced URL** | One URL within a reference. |
| **page-referenced sizes** | The sizes reached via references for a given attachment — including `srcset` siblings the browser never fetched. This is the set the panel acts on. |
| **row** | One attachment, gathering every reference that resolved to it. Row identity is the attachment, never the reference. |
| **report** | The ordered list of rows produced by a scan. A PHP structure, not JSON. |
| **page scan** | The operation. Internal vocabulary only — no UI string says "scan". |
| **Images** | The only user-facing name: the admin-bar node label. |

### Row states

| State | Means |
|---|---|
| `optimizable` | Work is available: at least one page-referenced size is not yet compressed. |
| `optimized` | Every page-referenced size is compressed. |
| `unsupported` | Resolved to an attachment, but we cannot act (fails `file_type_allowed()`, malformed metadata, stale variant). |
| `unresolved` | Could not be tied to anything in the media library. |

`optimizable` is deliberately not `unoptimized`: the common case is an attachment where
some page-referenced sizes are already done, and `unoptimized` would state something false
about it. `optimizable` claims only that there is work available, which is what the button
offers.

`unresolved` is deliberately not `external`, which the design used. The bucket includes
files under this site's own uploads directory that aren't attachments, and offloaded media
that *is* this site's media. Those are not external in any sense the user would recognise.
The state names what the classifier determined — resolution failed — rather than asserting
an unverified fact about where the file is hosted.

`optimized` is scoped to *page-referenced* sizes. A row can read `optimized` while the
Media Library column still reports the attachment incomplete, because the panel scoped
itself to what the page uses.

## Non-terms

Words that must **not** be used, because they are already taken or ambiguous.

| Word | Why not | Use instead |
|---|---|---|
| **candidate** | Triply overloaded: WF-1's path-guessing fallbacks, the HTML spec's "image candidate string", and the Figma labels' name for the first row group. | *lookup candidate* for the resolver's internal fallbacks (never a key, never a UI string); *referenced URL* for URLs in a `srcset`. |
| **source** | Collides with `Tiny_Source_Base` and with the literal `<source>` element. | *referenced URL* |
| **page image** | Collides with `Tiny_Image` (= attachment). | *reference* |
| **external** | Untrue for offloaded and under-uploads-but-not-an-attachment files. | *unresolved* |

## Constraints that shape the vocabulary

- **`ORIGINAL` is integer `0`.** Never key an array by size name where `0` can appear:
  `array_merge()` silently reindexes integer keys and `ksort()` orders them inconsistently
  against string keys. Use a list of `array( 'size' => …, … )` instead. (`compress-details.php:24`
  does the unsafe `ksort` today.)
- **The URL → size name map must not be cached.** Duplicate collapsing runs through
  `detect_duplicates()` against live settings, so canonical size names change when settings
  change. URL → attachment ID *is* cacheable.
- **Mappable ≠ actionable.** Duplicate-marked sizes are skipped by `compress()`, and sizes
  outside `get_active_tinify_sizes()` are the gap between what the panel shows and what
  compression will do.
