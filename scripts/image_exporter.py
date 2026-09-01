#!/usr/bin/env python
"""Bounded TWWATER image compositor for GIF, PDF and PPTX exports."""
from __future__ import annotations
import hashlib, io, json, os, re, sys, zipfile
from pathlib import Path
from xml.sax.saxutils import escape
from PIL import Image, ImageDraw, ImageFont, ImageOps

MAX_ITEMS = 40
MAX_PIXELS = 40_000_000
MAX_TOTAL_SOURCE_PIXELS = 200_000_000
MAX_SOURCE_BYTES = 629_145_600
MAX_ANNOTATIONS = 1000
FONT_CANDIDATES = [r"C:\Windows\Fonts\msjh.ttc", r"C:\Windows\Fonts\mingliu.ttc"]


def font(size: int):
    for candidate in FONT_CANDIDATES:
        if os.path.isfile(candidate):
            return ImageFont.truetype(candidate, size)
    return ImageFont.load_default()


def wrap_text(draw: ImageDraw.ImageDraw, text: str, text_font, max_width: int) -> str:
    """Wrap text by rendered width, including words wider than the box."""
    lines = []
    for source_line in text.splitlines() or ['']:
        line = ''
        for character in source_line:
            candidate = line + character
            if line and draw.textlength(candidate, font=text_font) > max_width:
                lines.append(line.rstrip())
                line = character.lstrip()
            else:
                line = candidate
        lines.append(line.rstrip())
    return '\n'.join(lines)


def fit_canvas(image: Image.Image, size=(1600, 900), background=(248, 247, 243)) -> tuple[Image.Image, tuple[int, int, int, int]]:
    image = ImageOps.exif_transpose(image).convert("RGB")
    image.thumbnail(size, Image.Resampling.LANCZOS)
    canvas = Image.new("RGB", size, background)
    offset_x, offset_y = (size[0] - image.width) // 2, (size[1] - image.height) // 2
    canvas.paste(image, (offset_x, offset_y))
    return canvas, (offset_x, offset_y, image.width, image.height)


def render_item(item: dict, include_annotations: bool) -> Image.Image:
    path = Path(str(item.get("path", "")))
    if not path.is_file() or path.is_symlink():
        raise ValueError("source image unavailable")
    with Image.open(path) as opened:
        if opened.width < 1 or opened.height < 1 or opened.width * opened.height > MAX_PIXELS:
            raise ValueError("source image dimensions invalid")
        image, content_box = fit_canvas(opened)
    if not include_annotations:
        return image
    overlay = Image.new('RGBA', image.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    label_font = font(24)
    for index, annotation in enumerate(item.get("annotations", []), 1):
        annotation_type = str(annotation.get("annotation_type", "rectangle")).lower()
        if annotation_type not in {'rectangle', 'arrow', 'highlight', 'text', 'number'}:
            raise ValueError('annotation_type invalid')
        geometry = annotation.get('geometry') if isinstance(annotation.get('geometry'), dict) else {}
        style = annotation.get('style') if isinstance(annotation.get('style'), dict) else {}
        x = max(0.0, min(1.0, float(annotation.get("x", 0))))
        y = max(0.0, min(1.0, float(annotation.get("y", 0))))
        w = max(1e-8, min(1.0 - x, float(annotation.get("width", 0))))
        h = max(1e-8, min(1.0 - y, float(annotation.get("height", 0))))
        offset_x, offset_y, content_width, content_height = content_box
        box = (
            round(offset_x + x * content_width),
            round(offset_y + y * content_height),
            round(offset_x + (x + w) * content_width),
            round(offset_y + (y + h) * content_height),
        )
        stroke = (220, 38, 38)
        stroke_width = max(1, min(12, int(style.get('strokeWidth', 5))))
        note = str(annotation.get("note", "")).strip()[:160]
        if annotation_type == 'highlight':
            alpha = round(255 * max(.05, min(1.0, float(style.get('opacity', .35)))))
            draw.rectangle(box, fill=(255, 216, 77, alpha), outline=(217, 155, 0, 255), width=stroke_width)
        elif annotation_type == 'arrow':
            x1=max(0.0,min(1.0,float(geometry.get('x1',x)))); y1=max(0.0,min(1.0,float(geometry.get('y1',y)))); x2=max(0.0,min(1.0,float(geometry.get('x2',x+w)))); y2=max(0.0,min(1.0,float(geometry.get('y2',y+h))))
            p1=(round(offset_x+x1*content_width),round(offset_y+y1*content_height)); p2=(round(offset_x+x2*content_width),round(offset_y+y2*content_height))
            draw.line((p1,p2),fill=stroke,width=stroke_width)
            import math
            angle=math.atan2(p2[1]-p1[1],p2[0]-p1[0]); length=20
            head=[p2,(round(p2[0]-length*math.cos(angle-.5)),round(p2[1]-length*math.sin(angle-.5))),(round(p2[0]-length*math.cos(angle+.5)),round(p2[1]-length*math.sin(angle+.5)))]
            draw.polygon(head,fill=stroke)
        elif annotation_type == 'text':
            text=str(geometry.get('text',note)).strip()[:500]
            box_width, box_height = box[2] - box[0], box[3] - box[1]
            if box_width > 0 and box_height > 0:
                text_layer = Image.new('RGBA', (box_width, box_height), (0, 0, 0, 0))
                text_draw = ImageDraw.Draw(text_layer)
                text_font = font(max(10,min(72,int(style.get('fontSize',28)))))
                wrapped = wrap_text(text_draw, text, text_font, max(1, box_width - 2))
                text_draw.multiline_text((1,0),wrapped,font=text_font,fill=stroke,stroke_width=1,stroke_fill=(255,255,255),spacing=4)
                overlay.alpha_composite(text_layer, (box[0], box[1]))
        elif annotation_type == 'number':
            marker=str(max(1,min(9999,int(geometry.get('number',index)))))
            box_width, box_height = box[2] - box[0], box[3] - box[1]
            if box_width > 0 and box_height > 0:
                marker_layer = Image.new('RGBA', (box_width, box_height), (0, 0, 0, 0))
                marker_draw = ImageDraw.Draw(marker_layer)
                diameter = min(box_width, box_height)
                left, top = (box_width - diameter) // 2, (box_height - diameter) // 2
                marker_draw.ellipse((left, top, left + diameter - 1, top + diameter - 1), fill=stroke)
                marker_size = max(1, diameter // 2)
                marker_font = font(marker_size)
                tb = marker_draw.textbbox((0,0), marker, font=marker_font)
                while marker_size > 1 and (tb[2] - tb[0] > diameter - 2 or tb[3] - tb[1] > diameter - 2):
                    marker_size -= 1
                    marker_font = font(marker_size)
                    tb = marker_draw.textbbox((0,0), marker, font=marker_font)
                tx = left + (diameter - (tb[2] - tb[0])) / 2 - tb[0]
                ty = top + (diameter - (tb[3] - tb[1])) / 2 - tb[1]
                marker_draw.text((tx,ty),marker,font=marker_font,fill=(255,255,255))
                overlay.alpha_composite(marker_layer, (box[0], box[1]))
        else:
            draw.rectangle(box, outline=stroke, width=stroke_width)
        if annotation_type in {'rectangle','arrow','highlight'}:
            label = f"{index}. {note}" if note else str(index)
            bbox = draw.textbbox((0, 0), label, font=label_font)
            tx, ty = box[0], max(0, box[1] - (bbox[3] - bbox[1]) - 10)
            draw.rectangle((tx, ty, min(image.width, tx + bbox[2] + 12), ty + bbox[3] + 8), fill=stroke)
            draw.text((tx + 6, ty + 3), label, font=label_font, fill=(255, 255, 255))
    offset_x, offset_y, content_width, content_height = content_box
    clipped = overlay.crop((offset_x, offset_y, offset_x + content_width, offset_y + content_height))
    image.paste(clipped, (offset_x, offset_y), clipped)
    return image


def validate_image(path: Path, extension: str) -> dict:
    expected_formats = {'png': 'PNG', 'jpg': 'JPEG', 'jpeg': 'JPEG', 'webp': 'WEBP', 'gif': 'GIF'}
    expected = expected_formats.get(extension.lower())
    if expected is None or not path.is_file() or path.is_symlink():
        raise ValueError('invalid image validation request')
    frames = 0
    total_pixels = 0
    with Image.open(path) as image:
        if image.format != expected:
            raise ValueError('image format mismatch')
        while True:
            if image.width < 1 or image.height < 1 or image.width * image.height > MAX_PIXELS:
                raise ValueError('source image dimensions invalid')
            image.load()
            frames += 1
            total_pixels += image.width * image.height
            if frames > 500 or total_pixels > MAX_TOTAL_SOURCE_PIXELS:
                raise ValueError('animated image exceeds bounds')
            try:
                image.seek(image.tell() + 1)
            except EOFError:
                break
    return {'ok': True, 'format': extension.lower(), 'frames': frames}


def validate_output(path: Path, fmt: str, expected_items: int) -> None:
    if not path.is_file() or path.is_symlink() or not 1 <= expected_items <= MAX_ITEMS:
        raise ValueError('invalid export output')
    if fmt == 'gif':
        frames = 0
        with Image.open(path) as image:
            if image.format != 'GIF':
                raise ValueError('invalid GIF output')
            while True:
                image.load(); frames += 1
                try:
                    image.seek(image.tell() + 1)
                except EOFError:
                    break
        if frames != expected_items:
            raise ValueError('GIF frame count mismatch')
        return
    if fmt == 'pdf':
        data = path.read_bytes()
        if not data.startswith(b'%PDF-') or not data.rstrip().endswith(b'%%EOF'):
            raise ValueError('invalid PDF output')
        match = re.search(rb'startxref\s+(\d+)\s+%%EOF\s*$', data)
        if match is None:
            raise ValueError('PDF xref missing')
        xref = int(match.group(1))
        if xref < 0 or xref >= len(data) or data[xref:xref + 4] != b'xref':
            raise ValueError('PDF xref invalid')
        pages = len(re.findall(rb'/Type\s*/Page(?!s)\b', data))
        if pages != expected_items or b'/Root' not in data[xref:]:
            raise ValueError('PDF page count invalid')
        return
    if fmt == 'pptx':
        with zipfile.ZipFile(path) as package:
            entries = package.namelist()
            if len(entries) != len(set(entries)):
                raise ValueError('PPTX duplicate item invalid')
            names = set(entries)
            if package.testzip() is not None or '[Content_Types].xml' not in names or 'ppt/presentation.xml' not in names:
                raise ValueError('invalid PPTX output')
            expected_slides = {f'ppt/slides/slide{index}.xml' for index in range(1, expected_items + 1)}
            expected_media = {f'ppt/media/image{index}.png' for index in range(1, expected_items + 1)}
            actual_slides = {name for name in names if re.fullmatch(r'ppt/slides/slide\d+\.xml', name)}
            actual_media = {name for name in names if name.startswith('ppt/media/')}
            if actual_slides != expected_slides or actual_media != expected_media:
                raise ValueError('PPTX item count invalid')
        return
    raise ValueError('invalid output format')


def pptx_xml(images: list[bytes], output: Path) -> None:
    ns_a = "http://schemas.openxmlformats.org/drawingml/2006/main"
    ns_p = "http://schemas.openxmlformats.org/presentationml/2006/main"
    ns_r = "http://schemas.openxmlformats.org/officeDocument/2006/relationships"
    content_overrides = ''.join(f'<Override PartName="/ppt/slides/slide{i}.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>' for i in range(1, len(images)+1))
    slide_ids = ''.join(f'<p:sldId id="{255+i}" r:id="rId{i+1}"/>' for i in range(1, len(images)+1))
    slide_rels = ''.join(f'<Relationship Id="rId{i+1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide{i}.xml"/>' for i in range(1, len(images)+1))
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/><Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/><Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/><Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>{content_overrides}</Types>''')
        z.writestr('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/></Relationships>')
        z.writestr('ppt/presentation.xml', f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation xmlns:a="{ns_a}" xmlns:r="{ns_r}" xmlns:p="{ns_p}"><p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst>{slide_ids}</p:sldIdLst><p:sldSz cx="12192000" cy="6858000" type="screen16x9"/><p:notesSz cx="6858000" cy="9144000"/></p:presentation>''')
        z.writestr('ppt/_rels/presentation.xml.rels', f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>{slide_rels}</Relationships>''')
        z.writestr('ppt/slideMasters/slideMaster1.xml', f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster xmlns:a="{ns_a}" xmlns:r="{ns_r}" xmlns:p="{ns_p}"><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree></p:cSld><p:clrMap accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" bg1="lt1" bg2="lt2" folHlink="folHlink" hlink="hlink" tx1="dk1" tx2="dk2"/><p:sldLayoutIdLst><p:sldLayoutId id="1" r:id="rId1"/></p:sldLayoutIdLst><p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles></p:sldMaster>''')
        z.writestr('ppt/slideMasters/_rels/slideMaster1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme" Target="../theme/theme1.xml"/></Relationships>')
        z.writestr('ppt/slideLayouts/slideLayout1.xml', f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout xmlns:a="{ns_a}" xmlns:r="{ns_r}" xmlns:p="{ns_p}" type="blank"><p:cSld name="Blank"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>''')
        z.writestr('ppt/slideLayouts/_rels/slideLayout1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="../slideMasters/slideMaster1.xml"/></Relationships>')
        z.writestr('ppt/theme/theme1.xml', f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="{ns_a}" name="TWWATER"><a:themeElements><a:clrScheme name="TWWATER"><a:dk1><a:srgbClr val="000000"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="1F497D"/></a:dk2><a:lt2><a:srgbClr val="EEECE1"/></a:lt2><a:accent1><a:srgbClr val="4F81BD"/></a:accent1><a:accent2><a:srgbClr val="C0504D"/></a:accent2><a:accent3><a:srgbClr val="9BBB59"/></a:accent3><a:accent4><a:srgbClr val="8064A2"/></a:accent4><a:accent5><a:srgbClr val="4BACC6"/></a:accent5><a:accent6><a:srgbClr val="F79646"/></a:accent6><a:hlink><a:srgbClr val="0000FF"/></a:hlink><a:folHlink><a:srgbClr val="800080"/></a:folHlink></a:clrScheme><a:fontScheme name="TWWATER"><a:majorFont><a:latin typeface="Arial"/></a:majorFont><a:minorFont><a:latin typeface="Arial"/></a:minorFont></a:fontScheme><a:fmtScheme name="TWWATER"><a:fillStyleLst/><a:lnStyleLst/><a:effectStyleLst/><a:bgFillStyleLst/></a:fmtScheme></a:themeElements></a:theme>''')
        for i, raw in enumerate(images, 1):
            z.writestr(f'ppt/media/image{i}.png', raw)
            z.writestr(f'ppt/slides/slide{i}.xml', f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld xmlns:a="{ns_a}" xmlns:r="{ns_r}" xmlns:p="{ns_p}"><p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/><p:pic><p:nvPicPr><p:cNvPr id="2" name="Image {i}"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></p:blipFill><p:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="12192000" cy="6858000"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic></p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>''')
            z.writestr(f'ppt/slides/_rels/slide{i}.xml.rels', f'''<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image{i}.png"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/></Relationships>''')


def main() -> int:
    if len(sys.argv) == 5 and sys.argv[1] == '--validate-output':
        validate_output(Path(sys.argv[2]), sys.argv[3].lower(), int(sys.argv[4]))
        print(json.dumps({'ok': True}))
        return 0
    if len(sys.argv) == 4 and sys.argv[1] == '--validate':
        print(json.dumps(validate_image(Path(sys.argv[2]), sys.argv[3])))
        return 0
    if len(sys.argv) != 3:
        raise ValueError("usage: image_exporter.py manifest.json output")
    manifest_path, output = Path(sys.argv[1]), Path(sys.argv[2])
    data = json.loads(manifest_path.read_text(encoding='utf-8'))
    items = data.get('items', [])
    fmt = str(data.get('format', '')).lower()
    if fmt not in {'gif', 'pdf', 'pptx'} or not 1 <= len(items) <= MAX_ITEMS:
        raise ValueError("invalid export request")
    if output.suffix.lower() != '.' + fmt:
        raise ValueError("output extension mismatch")
    source_bytes = 0
    annotation_count = 0
    source_pixels = 0
    for item in items:
        path = Path(str(item.get('path', '')))
        if not path.is_file() or path.is_symlink():
            raise ValueError("source image unavailable")
        source_bytes += path.stat().st_size
        annotation_count += len(item.get('annotations', []))
        expected_hash = str(item.get('expected_hash', '')).lower()
        if len(expected_hash) != 64 or any(character not in '0123456789abcdef' for character in expected_hash):
            raise ValueError('expected hash missing')
        digest = hashlib.sha256()
        with path.open('rb') as source:
            for chunk in iter(lambda: source.read(1024 * 1024), b''):
                digest.update(chunk)
        if digest.hexdigest() != expected_hash:
            raise ValueError('source hash mismatch')
        with Image.open(path) as probe:
            if probe.width < 1 or probe.height < 1 or probe.width * probe.height > MAX_PIXELS:
                raise ValueError("source image dimensions invalid")
            source_pixels += probe.width * probe.height
    if source_bytes > MAX_SOURCE_BYTES or annotation_count > MAX_ANNOTATIONS or source_pixels > MAX_TOTAL_SOURCE_PIXELS:
        raise ValueError("export work exceeds bounds")
    rendered = [render_item(item, bool(data.get('include_annotations', True))) for item in items]
    output.parent.mkdir(parents=True, exist_ok=True)
    if fmt == 'gif':
        duration = max(200, min(10000, int(data.get('frame_duration_ms', 1500))))
        frames = [image.convert('P', palette=Image.Palette.ADAPTIVE, colors=256) for image in rendered]
        frames[0].save(output, save_all=True, append_images=frames[1:], duration=duration, loop=0, optimize=False, disposal=2)
    elif fmt == 'pdf':
        rendered[0].save(output, 'PDF', save_all=True, append_images=rendered[1:], resolution=150.0)
    else:
        encoded = []
        for image in rendered:
            stream = io.BytesIO(); image.save(stream, 'PNG', optimize=True); encoded.append(stream.getvalue())
        pptx_xml(encoded, output)
    if not output.is_file() or output.stat().st_size < 100:
        raise ValueError("export output missing")
    validate_output(output, fmt, len(items))
    print(json.dumps({'ok': True, 'format': fmt, 'items': len(items), 'bytes': output.stat().st_size}))
    return 0


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(json.dumps({'ok': False, 'error': str(exc)[:200]}), file=sys.stderr)
        raise SystemExit(1)
