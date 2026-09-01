from pathlib import Path
import hashlib, importlib.util, json, os, subprocess, sys, tempfile, warnings, zipfile
from PIL import Image, ImageChops, ImageSequence

ROOT = Path(__file__).resolve().parents[1]
PYTHON = Path(r"C:\Users\gisadmin\AppData\Local\hermes\hermes-agent\venv\Scripts\python.exe")
EXPORTER = ROOT / "scripts" / "image_exporter.py"
exporter_source = EXPORTER.read_text(encoding='utf-8')
assert 'max(0.001' not in exporter_source and 'max(1e-8' in exporter_source, 'exporter must preserve server-accepted sub-0.001 normalized dimensions'
spec = importlib.util.spec_from_file_location("image_exporter", EXPORTER)
module = importlib.util.module_from_spec(spec)
assert spec and spec.loader
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory(prefix="twwater-image-export-") as raw:
    tmp = Path(raw)
    items = []
    for index, color in enumerate(((60, 130, 200), (80, 170, 110)), 1):
        path = tmp / f"source-{index}.png"
        Image.new("RGB", (640 + index * 20, 360), color).save(path)
        items.append({"path": str(path), "expected_hash": hashlib.sha256(path.read_bytes()).hexdigest(), "title": f"Image {index}", "annotations": [{"x": .1, "y": .1, "width": .3, "height": .2, "note": "修改此區"}]})
    portrait = tmp / "portrait.png"
    Image.new("RGB", (400, 800), (240, 240, 240)).save(portrait)
    validated = subprocess.run([str(PYTHON), str(EXPORTER), '--validate', str(portrait), 'png'], check=True, capture_output=True, text=True)
    assert json.loads(validated.stdout)['ok'] is True
    truncated_gif = tmp / 'truncated.gif'; truncated_gif.write_bytes(b'GIF89a' + b'\x01\x00\x01\x00' + b'\x00\x00\x00')
    rejected = subprocess.run([str(PYTHON), str(EXPORTER), '--validate', str(truncated_gif), 'gif'], capture_output=True, text=True)
    assert rejected.returncode != 0, 'truncated GIF must fail full decoder validation'
    mismatch_manifest = tmp / 'hash-mismatch.json'
    mismatch_manifest.write_text(json.dumps({'format': 'gif', 'items': [{**items[0], 'expected_hash': '0' * 64}]}), encoding='utf-8')
    mismatch = subprocess.run([str(PYTHON), str(EXPORTER), str(mismatch_manifest), str(tmp / 'hash-mismatch.gif')], capture_output=True, text=True)
    assert mismatch.returncode != 0 and 'source hash mismatch' in mismatch.stderr, 'worker must reject a snapshot hash mismatch'
    with Image.open(portrait) as portrait_source:
        _, content_box = module.fit_canvas(portrait_source)
    assert content_box == (600, 50, 400, 800), f"unexpected fitted content box: {content_box!r}"
    annotated = module.render_item({"path": str(portrait), "annotations": [{"x": .2, "y": .1, "width": .2, "height": .2, "note": "A"}]}, True)
    # Portrait content remains 400x800 and is centered at x=600, y=50.
    expected_edge = annotated.crop((675, 200, 686, 221))
    assert any(pixel[0] > 180 and pixel[1] < 100 for pixel in expected_edge.get_flattened_data()), "annotation must follow fitted image coordinates"
    baseline = module.render_item({"path": str(portrait), "annotations": []}, True)
    text_box = (640, 130, 760, 250)
    long_text = module.render_item({"path": str(portrait), "annotations": [{
        "annotation_type":"text", "x":.1, "y":.1, "width":.3, "height":.15,
        "geometry":{"text":"WRAP THIS LONG TEXT INSIDE ITS SAVED BOX"}, "style":{"fontSize":28}
    }]}, True)
    text_changes = ImageChops.difference(long_text, baseline)
    changed_box = text_changes.getbbox()
    assert changed_box is not None
    assert changed_box[0] >= text_box[0] and changed_box[1] >= text_box[1] and changed_box[2] <= text_box[2] and changed_box[3] <= text_box[3], f"text pixels escaped saved annotation box: {changed_box!r}"
    assert text_changes.crop((text_box[0], text_box[1] + 45, text_box[2], text_box[3])).getbbox() is not None, "long text must wrap onto another line inside its saved box"
    number_cases = [
        ("normal", {"annotation_type":"number", "x":.65, "y":.2, "width":.1, "height":.1, "geometry":{"number":7}}, (860, 210, 900, 290)),
        ("tiny edge", {"annotation_type":"number", "x":.98, "y":.1, "width":.02, "height":.02, "geometry":{"number":99}}, (992, 130, 1000, 146)),
    ]
    for case_name, number_annotation, number_box in number_cases:
        numbered = module.render_item({"path": str(portrait), "annotations": [number_annotation]}, True)
        number_changes = ImageChops.difference(numbered, baseline)
        changed_box = number_changes.getbbox()
        assert changed_box is not None
        assert changed_box[0] >= number_box[0] and changed_box[1] >= number_box[1] and changed_box[2] <= number_box[2] and changed_box[3] <= number_box[3], f"{case_name} number pixels escaped saved annotation box: {changed_box!r}"
    micro_source=tmp/'micro-source.png'; Image.new('RGB',(1600,900),(240,240,240)).save(micro_source)
    micro_baseline=module.render_item({"path":str(micro_source),"annotations":[]},True)
    micro_annotation={"annotation_type":"number","x":.5,"y":.5,"width":.0001,"height":.0001,"geometry":{"number":1}}
    micro_render=module.render_item({"path":str(micro_source),"annotations":[micro_annotation]},True)
    micro_changes=ImageChops.difference(micro_render,micro_baseline).getbbox()
    if micro_changes is not None:
        assert micro_changes[0]>=800 and micro_changes[1]>=450 and micro_changes[2]<=801 and micro_changes[3]<=451, f"sub-0.001 annotation escaped its saved box: {micro_changes!r}"
    typed = module.render_item({"path": str(portrait), "annotations": [
        {"annotation_type":"arrow","x":.1,"y":.1,"width":.2,"height":.2,"geometry":{"x1":.1,"y1":.1,"x2":.3,"y2":.3},"note":"arrow"},
        {"annotation_type":"highlight","x":.2,"y":.35,"width":.3,"height":.12,"style":{"opacity":.35},"note":"highlight"},
        {"annotation_type":"text","x":.2,"y":.55,"width":.3,"height":.1,"geometry":{"text":"文字"},"note":"text"},
        {"annotation_type":"number","x":.65,"y":.2,"width":.08,"height":.1,"geometry":{"number":7},"note":"number"}
    ]}, True)
    assert typed.tobytes() != module.render_item({"path": str(portrait), "annotations": []}, True).tobytes(), 'typed annotations must alter the export canvas'
    background = (248, 247, 243)
    edge_annotations = [
        {"annotation_type":"rectangle","x":.98,"y":.001,"width":.02,"height":.05,"note":"edge label"},
        {"annotation_type":"highlight","x":.98,"y":.001,"width":.02,"height":.05,"note":"edge label"},
        {"annotation_type":"arrow","x":.98,"y":.001,"width":.02,"height":.03,"geometry":{"x1":.98,"y1":.001,"x2":1,"y2":.03},"note":"edge label"},
        {"annotation_type":"text","x":.98,"y":.98,"width":.02,"height":.02,"geometry":{"text":"EDGE"},"note":"text"},
        {"annotation_type":"number","x":.98,"y":.98,"width":.02,"height":.02,"geometry":{"number":99},"note":"number"},
    ]
    for edge_annotation in edge_annotations:
        edge_render = module.render_item({"path": str(portrait), "annotations": [edge_annotation]}, True)
        outside = [edge_render.crop((0, 0, 1600, 50)), edge_render.crop((0, 850, 1600, 900)), edge_render.crop((0, 50, 600, 850)), edge_render.crop((1000, 50, 1600, 850))]
        assert all(pixel == background for region in outside for pixel in region.get_flattened_data()), f"{edge_annotation['annotation_type']} rendering must be clipped to fitted source content"
    items[0]['annotations'].extend([
        {"annotation_type":"arrow","x":.1,"y":.45,"width":.25,"height":.2,"geometry":{"x1":.1,"y1":.45,"x2":.35,"y2":.65},"note":"arrow"},
        {"annotation_type":"highlight","x":.45,"y":.1,"width":.25,"height":.15,"style":{"opacity":.35},"note":"highlight"},
        {"annotation_type":"text","x":.45,"y":.4,"width":.3,"height":.1,"geometry":{"text":"文字"},"note":"text"},
        {"annotation_type":"number","x":.8,"y":.2,"width":.08,"height":.1,"geometry":{"number":7},"note":"number"}
    ])
    for fmt in ("gif", "pdf", "pptx"):
        manifest = tmp / f"{fmt}.json"
        output = tmp / f"result.{fmt}"
        manifest.write_text(json.dumps({"format": fmt, "include_annotations": True, "frame_duration_ms": 500, "items": items}, ensure_ascii=False), encoding="utf-8")
        result = subprocess.run([str(PYTHON), str(EXPORTER), str(manifest), str(output)], capture_output=True, text=True, timeout=90)
        assert result.returncode == 0, (fmt, result.stdout, result.stderr)
        assert output.stat().st_size > 100, fmt
        module.validate_output(output, fmt, 2)
        wrong_count_rejected = False
        try: module.validate_output(output, fmt, 3)
        except ValueError: wrong_count_rejected = True
        assert wrong_count_rejected, f'{fmt} output must enforce the expected item count'
        if fmt == "gif":
            with Image.open(output) as image:
                assert sum(1 for _ in ImageSequence.Iterator(image)) == 2
        elif fmt == "pdf":
            assert output.read_bytes().startswith(b"%PDF-")
        else:
            with zipfile.ZipFile(output) as package:
                names = set(package.namelist())
                assert "ppt/presentation.xml" in names
                assert "ppt/slides/slide1.xml" in names and "ppt/slides/slide2.xml" in names
                assert package.testzip() is None
            extra_pptx = tmp / "extra-items.pptx"
            extra_pptx.write_bytes(output.read_bytes())
            with zipfile.ZipFile(extra_pptx, "a", zipfile.ZIP_DEFLATED) as package:
                package.writestr("ppt/slides/slide3.xml", "<extra/>")
                package.writestr("ppt/media/image3.png", b"extra")
            extra_rejected = False
            try: module.validate_output(extra_pptx, "pptx", 2)
            except ValueError: extra_rejected = True
            assert extra_rejected, "PPTX validator must reject extra slide/media entries"
            duplicate_pptx = tmp / "duplicate-items.pptx"
            duplicate_pptx.write_bytes(output.read_bytes())
            with warnings.catch_warnings():
                warnings.simplefilter('ignore', UserWarning)
                with zipfile.ZipFile(duplicate_pptx, "a", zipfile.ZIP_DEFLATED) as package:
                    package.writestr("ppt/slides/slide1.xml", "<duplicate/>")
                    package.writestr("ppt/media/image1.png", b"duplicate")
            duplicate_rejected = False
            try: module.validate_output(duplicate_pptx, "pptx", 2)
            except ValueError: duplicate_rejected = True
            assert duplicate_rejected, "PPTX validator must reject duplicate slide/media ZIP members"
            office = Path(r"C:\Program Files\LibreOffice\program\soffice.exe")
            if office.is_file():
                converted = tmp / "libreoffice"
                converted.mkdir()
                check = subprocess.run([str(office), "--headless", "--convert-to", "pdf", "--outdir", str(converted), str(output)], capture_output=True, text=True, timeout=120)
                assert check.returncode == 0, (check.stdout, check.stderr)
                pdf = converted / "result.pdf"
                assert pdf.is_file() and pdf.read_bytes().startswith(b"%PDF-")
    executable = Path(r"C:\TWWATER\runtime\pre-upload-previews\image-export-runtime\image_exporter.exe")
    if executable.is_file():
        for fmt in ("gif", "pdf", "pptx"):
            manifest = tmp / f"exe-{fmt}.json"
            output = tmp / f"exe-result.{fmt}"
            manifest.write_text(json.dumps({"format": fmt, "include_annotations": True, "frame_duration_ms": 500, "items": items}, ensure_ascii=False), encoding="utf-8")
            result = subprocess.run([str(executable), str(manifest), str(output)], capture_output=True, text=True, timeout=120)
            assert result.returncode == 0, (fmt, result.stdout, result.stderr)
            assert output.stat().st_size > 100, fmt
print("[OK] GIF/PDF/PPTX image exports validated.")
