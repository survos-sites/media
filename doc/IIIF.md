# IIIF Integration

This document describes how to use the IIIF (International Image Interoperability Framework) endpoints provided by this application.

## Overview

The application exposes IIIF Image API 2.0 and Presentation API 3.0 endpoints via the `IiifController`. The endpoints leverage the `survos/iiif-bundle` for manifest generation.

## Endpoints

### IIIF Image API 2.0

| Endpoint | Description |
|----------|-------------|
| `GET /iiif/2/{id}/info.json` | Get image information (dimensions, profile, sizes) |
| `GET /iiif/2/{id}` | Alias for info.json |
| `GET /iiif/2/{id}/{region}/{size}/{rotation}/{quality}.{format}` | Get image tile |

### IIIF Presentation API 3.0

| Endpoint | Description |
|----------|-------------|
| `GET /iiif/3/{id}/manifest` | Get IIIF Manifest for an asset |

## Using the Endpoints

### Get Image Information

```bash
# Get info.json for asset with ID "abc123"
curl https://yourdomain.example/iiif/2/abc123/info.json
```

Example response:
```json
{
    "@context": "http://iiif.io/api/image/2/context.json",
    "@id": "https://yourdomain.example/iiif/2/abc123",
    "protocol": "http://iiif.io/api/image",
    "width": 3000,
    "height": 4000,
    "profile": ["http://iiif.io/api/image/2/level0.json"],
    "tiles": [],
    "sizes": [
        {"width": 64, "height": 85},
        {"width": 128, "height": 170},
        {"width": 256, "height": 341},
        {"width": 512, "height": 683},
        {"width": 1024, "height": 1365},
        {"width": 2048, "height": 2730}
    ]
}
```

### Get Image

```bash
# Get full image at maximum size
curl -L "https://yourdomain.example/iiif/2/abc123/full/max/0/default.jpg"

# Get thumbnail (192px width)
curl -L "https://yourdomain.example/iiif/2/abc123/full/192,/0/default.jpg"
```

Parameters:
- **region**: `full` (currently only full region is supported)
- **size**: `max`, `full`, or `{width},` (e.g., `192,`)
- **rotation**: `0` (currently only 0 rotation is supported)
- **quality**: `default`, `color`, `gray`, `bitonal`
- **format**: `jpg` (only JPEG supported)

### Get Manifest

```bash
# Get IIIF Manifest for a single-page document
curl https://yourdomain.example/iiif/3/abc123/manifest
```

Example response:
```json
{
    "@context": "http://iiif.io/api/presentation/3/context.json",
    "id": "https://yourdomain.example/iiif/3/abc123/manifest",
    "type": "Manifest",
    "label": { "en": ["Document Title"] },
    "thumbnail": [{
        "id": "https://yourdomain.example/iiif/2/abc123/full/192,/0/default.jpg",
        "type": "Image",
        "format": "image/jpeg",
        "width": 192,
        "height": 192
    }],
    "items": [{
        "id": "https://yourdomain.example/iiif/3/abc123/manifest/canvas/p1",
        "type": "Canvas",
        "width": 3000,
        "height": 4000,
        "items": [{
            "id": "https://yourdomain.example/iiif/3/abc123/manifest/page/p1",
            "type": "AnnotationPage",
            "items": [{
                "id": "https://yourdomain.example/iiif/3/abc123/manifest/annotation/p1-image",
                "type": "Annotation",
                "motivation": "painting",
                "target": "https://yourdomain.example/iiif/3/abc123/manifest/canvas/p1",
                "body": {
                    "id": "https://yourdomain.example/iiif/2/abc123/full/max/0/default.jpg",
                    "type": "Image",
                    "format": "image/jpeg",
                    "width": 3000,
                    "height": 4000,
                    "service": [{
                        "id": "https://yourdomain.example/iiif/2/abc123",
                        "type": "ImageService2",
                        "profile": "level0"
                    }]
                }
            }]
        }]
    }]
}
```

## Integration with IIIF Viewers

### Mirador

```html
<script src="https://mirador.org/downloads/mirador-v3.min.js"></script>
<div id="mirador" style="height: 600px;"></div>
<script>
  const mirador = Mirador.viewer({
    id: 'mirador',
    windows: [{
      manifestId: 'https://yourdomain.example/iiif/3/abc123/manifest'
    }]
  });
</script>
```

### UV (Universal Viewer)

```html
<script src="https://cdn.jsdelivr.net/npm/universalviewer@4.1/dist/uv.min.js"></script>
<div id="uv" data-url="https://yourdomain.example/iiif/3/abc123/manifest"></div>
<script>
  UV.init(document.getElementById('uv'));
</script>
```

## Using the iiif-bundle for Custom Manifests

For more complex manifest generation (e.g., multi-page documents, collections, metadata), use the `ManifestBuilder` from the bundle:

```php
use Survos\IiifBundle\Builder\ManifestBuilder;
use Survos\IiifBundle\Model\ImageService3;
use Survos\IiifBundle\Enum\ViewingDirection;
use Survos\IiifBundle\Enum\Behavior;

$builder = new ManifestBuilder('https://example.org/iiif/item-123/manifest');

$builder
    ->setLabel('en', 'My Collection Item')
    ->setSummary('en', 'Description of the item')
    ->addMetadata('en', 'Date', 'en', '1892')
    ->setRights('http://creativecommons.org/publicdomain/mark/1.0/')
    ->setRequiredStatement('en', 'Attribution', 'My Museum')
    ->setViewingDirection(ViewingDirection::LEFT_TO_RIGHT)
    ->setBehavior(Behavior::PAGED);

// Add canvas
$canvas = $builder->addCanvas(
    id: 'https://example.org/iiif/item-123/canvas/p1',
    label: 'Page 1',
    width: 3000,
    height: 4000,
);

// Add image to canvas
$canvas->addImage(
    annotationId: 'https://example.org/iiif/item-123/canvas/p1/anno/image',
    imageUrl: 'https://example.org/scans/page-1.jpg',
    format: 'image/jpeg',
    width: 3000,
    height: 4000,
    service: new ImageService3(
        id: 'https://iiif.example.org/image/page-1',
        profile: 'level2'
    ),
);

// Output JSON
$json = $builder->toJson();
```

## Asset Requirements

For best results with IIIF viewers:
- Assets should have `width` and `height` metadata stored
- Use JPEG format for compatibility
- Include meaningful titles in the asset context
