# Presentation preview worker

`presentation_preview_worker.php` converts one PPTX version to a protected,
immutable PDF generation. It does not change IIS, SQL Server, scheduled tasks,
the source PPTX, or any portal setting.

## Output contract

For a PPTX with SHA-256 `<hash>`, the worker atomically publishes:

```text
<PreviewRoot>\<first-two-hash-characters>\<hash>\
  manifest.json
  preview.pdf
```

`manifest.json` contains only a safe relative source path and artifact metadata:

- `schemaVersion`
- `sourceRelativePath`
- `sourceSha256` and `sourceSizeBytes`
- `pdfSha256`, `pdfSizeBytes`, and `pdfFile`
- `slideCount`
- `converter` and `converterVersion`
- `createdUtc`

The hash directory is immutable. A repeat job validates and reuses a matching
generation. It refuses to overwrite a conflicting or damaged generation.

## Security properties

- `RelativePath` must remain below the configured real `SourceRoot`.
- Source path components that are junctions, symbolic links, or other reparse
  points are rejected.
- The worker opens the source with read-only access and blocks writers while it
  creates a private snapshot. LibreOffice only receives that snapshot.
- Every conversion has a new temporary directory and a separate LibreOffice
  user profile.
- PDF and manifest are built in a same-volume staging directory and published
  together with an atomic directory move.
- Raw LibreOffice output and absolute source paths are not returned to callers.
  Failure JSON contains only job ID, safe code/stage, exception type, exit code,
  and time.
- Preview and runtime roots must be outside and non-nested with the document
  source. Keep both outside the IIS website root.
- LibreOffice and the queue run as `IIS APPPOOL\TWWATER_PortalPool`, with source
  Read & Execute and preview/runtime Modify only. The periodic Task Scheduler
  wake-up uses `LOCAL SERVICE` by default. An explicit `LocalSystem` fallback is
  available for hosts where endpoint security blocks PowerShell under `LOCAL
  SERVICE`. Neither identity receives a database password, portal login, cookie,
  or token; conversion still runs under the AppPool identity.

## Dependency diagnosis

Production supplies the explicit LibreOffice executable through the AppPool
`PORTAL_LIBREOFFICE_PATH` environment value. The queue passes that fixed value
to the PHP worker as `--libreoffice-path`. Supported executable names are:

```text
%ProgramFiles%\LibreOffice\program\soffice.com
%ProgramFiles%\LibreOffice\program\soffice.exe
%ProgramFiles(x86)%\LibreOffice\program\soffice.com
%ProgramFiles(x86)%\LibreOffice\program\soffice.exe
PATH: soffice.com or soffice.exe
```

If none exists, it returns `LIBREOFFICE_NOT_FOUND`. Installing LibreOffice and
supplying the explicit `soffice.com` path is preferred for production.

## One-file execution example

Run this from a controlled local PowerShell session. Replace the relative path
with a PPTX already present below the physical document cache.

```powershell
& 'C:\PHP\8.5.9\php.exe' -f `
  'C:\web\gary\TWWATER\scripts\presentation_preview_worker.php' -- `
  --source-root 'C:\TWWATER\document-cache' `
  --relative-path 'project\briefing.pptx' `
  --preview-root 'C:\TWWATER\runtime\presentation-previews' `
  --runtime-root 'C:\TWWATER\runtime\presentation-preview' `
  --libreoffice-path 'C:\Program Files\LibreOffice\program\soffice.com' `
  --expected-source-sha256 '<64-lowercase-hex-source-hash>' `
  --emit-json
```

The portal/queue integration should always pass the indexed source hash through
`--expected-source-sha256`. A mismatch returns `SOURCE_VERSION_MISMATCH`; the caller
must enqueue a new job rather than attaching annotations to the wrong version.

## Safe test commands

The default test performs static parsing and negative path-validation only. It
does not invoke LibreOffice or modify IIS/scheduled tasks:

```powershell
& 'C:\web\gary\TWWATER\scripts\test_presentation_preview_worker.ps1'
```

After LibreOffice is installed, an operator may run an isolated integration
conversion. The test copies the sample into a temporary directory below the
project, verifies source hash/read-only integrity and idempotency, then removes
the test directory:

```powershell
& 'C:\web\gary\TWWATER\scripts\test_presentation_preview_worker.ps1' `
  -LibreOfficePath 'C:\Program Files\LibreOffice\program\soffice.com' `
  -SamplePptx 'C:\TWWATER\document-cache\project\briefing.pptx'
```

## Password-free scheduled queue wake-up

The installer creates a scheduled task that does **not** execute LibreOffice and
does not read IIS configuration. Every interval it writes a short-lived JSON
marker into a dedicated ACL directory and calls the fixed HTTPS endpoint through
`127.0.0.1` with the production Host/SNI name. The endpoint rejects non-loopback
clients, requires the one-time marker, checks its nonce and 10-minute freshness,
and atomically claims it. IIS then starts the CLI queue asynchronously under the
existing AppPool identity, so an ordinary page request never waits for a PPTX
conversion.

Run once from an elevated PowerShell window after migration 006, LibreOffice,
AppPool environment values, and the three protected directories are ready:

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force

& 'C:\web\gary\TWWATER\scripts\install_presentation_preview_scheduler.ps1' `
  -PhpPath 'C:\PHP\8.5.9\php.exe' `
  -SessionSavePath 'C:\TWWATER\runtime\sessions' `
  -DocumentRoot 'C:\TWWATER\document-cache' `
  -PresentationPreviewRoot 'C:\TWWATER\runtime\presentation-previews' `
  -PresentationRuntimeRoot 'C:\TWWATER\runtime\presentation-preview' `
  -AppPoolName 'TWWATER_PortalPool'
```

The installer grants the AppPool Read & Execute on the document cache and
Modify only on the two presentation output/runtime roots. It grants the selected
task identity Modify only on the trigger directory. The scheduled action uses the
credentialless PHP CLI wake-up script, rather than PowerShell: it can only create
the protected marker and POST to the fixed certificate-validated loopback HTTPS
route. IIS then claims the marker and starts the actual PHP/LibreOffice work as
the AppPool identity. Before registering the recurring task, the installer runs a
one-time task as that same identity; the task proves the marker, loopback TLS
route, AppPool environment, SQL schema, directory ACLs, PHP worker, and explicit LibreOffice
path without claiming a queue job.

The PHP CLI wake-up avoids the managed endpoint-security rule that previously
blocked scheduled PowerShell. `LocalService` remains the preferred identity when
the host permits it. On this server, Task Scheduler still terminates a
`LocalService` task before the PHP process starts; the approved fallback is
therefore `LocalSystem`. Do not weaken endpoint-security DCOM permissions merely
to bypass a health check. The `LocalSystem` task remains limited to the fixed
PHP signal, the protected trigger directory, and the fixed loopback HTTPS route;
IIS AppPool remains responsible for the actual queue and LibreOffice work. The
queue launches `presentation_preview_worker.php`, and that PHP worker launches
the fixed `soffice.com` executable directly; neither production step invokes
PowerShell.

```powershell
& 'C:\web\gary\TWWATER\scripts\install_presentation_preview_scheduler.ps1' `
  -PhpPath 'C:\PHP\8.5.9\php.exe' `
  -SessionSavePath 'C:\TWWATER\runtime\sessions' `
  -DocumentRoot 'C:\TWWATER\document-cache' `
  -PresentationPreviewRoot 'C:\TWWATER\runtime\presentation-previews' `
  -PresentationRuntimeRoot 'C:\TWWATER\runtime\presentation-preview' `
  -AppPoolName 'TWWATER_PortalPool' `
  -TaskIdentity 'LocalSystem' `
  -ReplaceExisting
```

To rerun only that non-mutating health check manually (including from Task
Scheduler), use PHP CLI rather than the legacy PowerShell signal script:

```powershell
& 'C:\PHP\8.5.9\php.exe' -n -d display_errors=0 -d log_errors=0 `
  -f 'C:\web\gary\TWWATER\scripts\signal_presentation_preview_queue.php' -- `
  --signal-root 'C:\TWWATER\runtime\sessions\presentation-preview-trigger' `
  --health-check
```

The task action contains only fixed paths, host name, port, and interval. The
database password remains solely in the existing IIS AppPool configuration and
is inherited in memory by the AppPool-launched CLI child; it is never copied to
Task Scheduler, a command line, this project, or a log file. AppPool environment
values are protected by Windows ACLs, not by Base64 encryption.
