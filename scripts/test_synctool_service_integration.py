import ctypes
import datetime as dt
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
DOTNET = Path(r"C:/Program Files/dotnet/dotnet.exe")
SERVICE_PROJECT = ROOT / "sync-service" / "Twwater.SyncService" / "Twwater.SyncService.csproj"
FAKE_PROJECT = ROOT / "sync-service" / "Twwater.SyncService.TestTool" / "Twwater.SyncService.TestTool.csproj"
SERVICE_EXE = ROOT / "sync-service" / "Twwater.SyncService" / "bin" / "Release" / "net8.0-windows" / "win-x64" / "Twwater.SyncService.exe"
FAKE_EXE = ROOT / "sync-service" / "Twwater.SyncService.TestTool" / "bin" / "Release" / "net8.0-windows" / "win-x64" / "Twwater.SyncService.TestTool.exe"
TEMP = Path(os.environ.get("LOCALAPPDATA", tempfile.gettempdir())) / "Temp"


def run(*args: str) -> None:
    subprocess.run(args, cwd=ROOT, check=True, stdout=subprocess.DEVNULL)


def wait_for(predicate, timeout=20):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        value = predicate()
        if value:
            return value
        time.sleep(0.2)
    raise AssertionError("timed out waiting for service state")


def write_json(path: Path, value: dict) -> None:
    path.write_text(json.dumps(value), encoding="utf-8")


def setup_runtime() -> Path:
    root = Path(tempfile.mkdtemp(prefix="twwater-sync-service-", dir=TEMP))
    for name in ("service", "requests", "results", "history", "cancel"):
        (root / name).mkdir()
    return root


def request(root: Path, kind: str, preview_id="") -> str:
    request_id = secrets.token_hex(16)
    write_json(root / "requests" / f"{request_id}.json", {
        "schema_version": 1,
        "request_id": request_id,
        "kind": kind,
        "direction": "drive_to_library",
        "preview_id": preview_id,
        "requested_at": dt.datetime.now(dt.timezone.utc).isoformat(),
        "requested_by": 7,
    })
    return request_id


def service_config(root: Path, fake_config: Path) -> None:
    write_json(root / "service" / "service-config.json", {
        "schema_version": 1,
        "sync_tool_exe": str(FAKE_EXE),
        "sync_tool_config": str(fake_config),
        "job_id": "integration-test",
    })


def result(root: Path, request_id: str):
    path = root / "results" / f"{request_id}.json"
    return json.loads(path.read_text(encoding="utf-8")) if path.exists() else None


def process_alive(pid: int) -> bool:
    handle = ctypes.windll.kernel32.OpenProcess(0x1000, False, pid)
    if not handle:
        return False
    ctypes.windll.kernel32.CloseHandle(handle)
    return True


run(str(DOTNET), "build", str(SERVICE_PROJECT), "--configuration", "Release", "--runtime", "win-x64", "--no-restore")
run(str(DOTNET), "build", str(FAKE_PROJECT), "--configuration", "Release", "--runtime", "win-x64")
assert SERVICE_EXE.is_file() and FAKE_EXE.is_file()

roots = []
try:
    # A different execute preview ID from the CLI must fail closed.
    root = setup_runtime(); roots.append(root)
    fake = root / "fake.json"
    requested_preview = "1" * 32
    write_json(fake, {"sleep_seconds": 0, "output_preview_id": "2" * 32, "spawn_child": False})
    service_config(root, fake)
    execute_id = request(root, "execute", requested_preview)
    run(str(SERVICE_EXE), "--once", f"--root={root}")
    execute_result = result(root, execute_id)
    assert execute_result["status"] == "failed"
    assert execute_result["error_code"] == "preview_output_mismatch", execute_result
    assert execute_result["preview_id"] == requested_preview
    assert execute_result["output_preview_id"] == "2" * 32
    assert len(execute_result["worker_build_sha256"]) == 64
    assert execute_result["executor_sid"].startswith("S-")
    assert len(execute_result["sync_tool_build_sha256"]) == 64

    # A present but malformed preview ID is not the compatible-null CLI contract.
    root = setup_runtime(); roots.append(root)
    fake = root / "fake.json"
    write_json(fake, {"sleep_seconds": 0, "output_preview_id": "malformed-non-null", "spawn_child": False})
    service_config(root, fake)
    malformed_id = request(root, "execute", requested_preview)
    run(str(SERVICE_EXE), "--once", f"--root={root}")
    malformed = result(root, malformed_id)
    assert malformed["status"] == "failed"
    assert malformed["error_code"] == "preview_output_malformed"

    # The generic SyncTool contract returns JSON null after internally validating RequiredPreviewId.
    root = setup_runtime(); roots.append(root)
    fake = root / "fake.json"
    write_json(fake, {"sleep_seconds": 0, "output_preview_id": None, "spawn_child": False})
    service_config(root, fake)
    compatible_id = request(root, "execute", requested_preview)
    run(str(SERVICE_EXE), "--once", f"--root={root}")
    compatible = result(root, compatible_id)
    assert compatible["status"] == "success"
    assert compatible["preview_id"] == requested_preview and compatible["output_preview_id"] is None

    # Request-specific setup failure must remain visible with its owner and kind.
    root = setup_runtime(); roots.append(root)
    fake = root / "fake.json"
    write_json(fake, {})
    service_config(root, fake)
    cfg = json.loads((root / "service" / "service-config.json").read_text(encoding="utf-8"))
    cfg["sync_tool_exe"] = str(root / "missing-tool.exe")
    write_json(root / "service" / "service-config.json", cfg)
    failed_id = request(root, "preview")
    run(str(SERVICE_EXE), "--once", f"--root={root}")
    setup_failed = result(root, failed_id)
    assert setup_failed["status"] == "failed"
    assert setup_failed["kind"] == "preview" and setup_failed["requested_by"] == 7
    assert setup_failed["error_code"] == "service_or_cli_start_failed"

    # Cancellation refreshes heartbeat and kills the complete process tree.
    root = setup_runtime(); roots.append(root)
    fake = root / "fake.json"
    child_pid_file = root / "child.pid"
    write_json(fake, {"sleep_seconds": 30, "output_preview_id": "3" * 32, "spawn_child": True, "child_pid_file": str(child_pid_file)})
    service_config(root, fake)
    preview_id = request(root, "preview")
    worker = subprocess.Popen([str(SERVICE_EXE), "--once", f"--root={root}"], cwd=ROOT)
    wait_for(lambda: result(root, preview_id) and result(root, preview_id)["status"] == "running")
    first_heartbeat = wait_for(lambda: json.loads((root / "results" / "service-status.json").read_text(encoding="utf-8"))["updated_at"])
    child_pid = int(wait_for(lambda: child_pid_file.read_text().strip() if child_pid_file.exists() else None))
    time.sleep(6)
    second_heartbeat = json.loads((root / "results" / "service-status.json").read_text(encoding="utf-8"))["updated_at"]
    assert second_heartbeat > first_heartbeat
    (root / "cancel" / f"{preview_id}.cancel").write_text("cancel", encoding="utf-8")
    assert worker.wait(timeout=20) == 0
    cancelled = result(root, preview_id)
    assert cancelled["status"] == "aborted_unverified" and cancelled["error_code"] == "forced_termination"
    wait_for(lambda: not process_alive(child_pid))

    # Retention repeats while the service stays up, not only at startup.
    root = setup_runtime(); roots.append(root)
    worker = subprocess.Popen([str(SERVICE_EXE), f"--root={root}", "--prune-interval-seconds=1", "--run-seconds=4"], cwd=ROOT)
    wait_for(lambda: (root / "results" / "service-status.json").exists())
    expired = root / "history" / "expired.jsonl"
    expired.write_text("old", encoding="utf-8")
    old = time.time() - 91 * 86400
    os.utime(expired, (old, old))
    assert worker.wait(timeout=10) == 0
    assert not expired.exists()
    stopped = json.loads((root / "results" / "service-status.json").read_text(encoding="utf-8"))
    assert stopped["status"] == "stopped"
    assert len(stopped["worker_build_sha256"]) == 64
    assert stopped["executor_sid"].startswith("S-")

    print("SYNCTOOL_SERVICE_INTEGRATION_OK")
finally:
    for root in roots:
        shutil.rmtree(root, ignore_errors=True)
