# Presentation review frontend contract

The presentation page loads only same-origin scripts and API resources. The
original PPTX is fetched with the user's existing authenticated session and is
parsed in the browser; opening the viewer never waits for a PDF conversion.

## Runtime files

- `presentation.js`
- `pptx-renderer.js`
- `presentation.css`
- `vendor/jszip/jszip.min.js` (JSZip 3.10.1, MIT option)
- `vendor/fabric/index.min.mjs`

The renderer reads PresentationML, relationships, theme colors, slide masters,
layouts, text, common shapes, pictures, connectors and tables, then renders SVG
into the existing Canvas. Unsupported chart or SmartArt content is represented
by a stable placeholder. The page CSP permits only same-origin scripts and
fetches plus the existing `data:`/`blob:` image sources used during SVG rasterization.

## Root element

```html
<section
  id="presentation-review-app"
  data-document-id="DOCUMENT-UUID"
  data-manifest-url="?action=presentation_manifest&amp;id=DOCUMENT-UUID"
  data-annotation-save-url="?action=presentation_annotation_save&amp;id=DOCUMENT-UUID"
  data-annotation-delete-url="?action=presentation_annotation_delete&amp;id=DOCUMENT-UUID"
  data-request-create-url="?action=presentation_request_create&amp;id=DOCUMENT-UUID"
  data-csrf-token="SESSION-CSRF-TOKEN">
  <div class="presentation-loading">正在下載並解析 PPTX…</div>
</section>
```

The source URL is returned by the authenticated manifest. All URLs are rejected
unless they resolve to the current origin.

## Manifest response

```json
{
  "document": {
    "id": "uuid",
    "title": "Title",
    "file_name": "deck.pptx",
    "source_url": "?action=inline&id=uuid",
    "source_size_bytes": 123456,
    "content_hash": "64-lowercase-hex"
  },
  "version": {"id": "uuid", "content_hash": "64-lowercase-hex"},
  "rendition": {"pdf_url": "?action=presentation_asset&...", "page_count": 10},
  "slides": [
    {"slide_number": 1, "title": "第 1 頁", "asset_hash": null, "thumbnail_url": null}
  ],
  "annotations": [
    {
      "id": "uuid",
      "row_version": "0x0000000000000001",
      "version_id": "uuid",
      "slide_number": 1,
      "type": "rectangle",
      "x": 0.1,
      "y": 0.2,
      "width": 0.3,
      "height": 0.1,
      "geometry": {"x": 0.1, "y": 0.2, "width": 0.3, "height": 0.1},
      "style": {"stroke": "#d83b3b", "opacity": 1, "strokeWidth": 3},
      "comment": "Update this value"
    }
  ],
  "permissions": {"view": true, "annotate": true, "create_request": true}
}
```

`document.source_url` is sufficient for immediate read-only preview. A version
id, content hash and existing server rendition/slide map are additionally
required before the annotation layer is enabled. This preserves existing saved
annotation foreign keys while removing PDF readiness from the viewing path.

## Browser preview integrity and limits

The client checks the authenticated response length against the catalog size,
calculates SHA-256 with Web Crypto when a catalog hash exists, and rejects a
changed or incomplete source. PPTX input is limited to 120 MB, 6,000 ZIP entries,
1,000 slides, 6 MB per XML part and 32 MB per embedded raster image. External
package relationships are never fetched.

## Annotation save request

The frontend sends a same-origin `POST`, JSON content type, session cookies and
the `X-CSRF-Token` header. Coordinates are normalized to the `0..1` range.

```json
{
  "document_id": "uuid",
  "version_id": "uuid",
  "content_hash": "64-lowercase-hex",
  "annotations": [
    {
      "id": "uuid-or-empty-for-create",
      "row_version": "required-for-update",
      "slide_number": 1,
      "type": "rectangle",
      "x": 0.1,
      "y": 0.2,
      "width": 0.3,
      "height": 0.1,
      "geometry": {"x": 0.1, "y": 0.2, "width": 0.3, "height": 0.1},
      "style": {"stroke": "#d83b3b", "opacity": 1, "strokeWidth": 3},
      "comment": "Update this value"
    }
  ],
  "deleted_annotation_ids": [
    {"id": "uuid", "row_version": "0x0000000000000001"}
  ]
}
```

The UI calls the annotation type `number`; its API type is `marker`. Arrow
geometry additionally contains a normalized `points` array so direction is
preserved. HTTP `409` is treated as a version conflict and never retried
silently.

## Change request

Only saved annotations can be submitted to the server:

```json
{
  "version_id": "uuid",
  "content_hash": "64-lowercase-hex",
  "title": "修改：簡報標題",
  "instruction": "Overall instruction",
  "annotation_ids": ["annotation-uuid"],
  "submit": false
}
```

The copy and download buttons also generate a local Markdown request. Values
from the manifest and user input are inserted with DOM `textContent`; the
frontend does not use `innerHTML`, `eval`, external URLs or expose the CSRF
token in exported content.
