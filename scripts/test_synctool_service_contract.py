from pathlib import Path
source=Path(__file__).resolve().parents[1]/'sync-service'/'Twwater.SyncService'/'Program.cs'
text=source.read_text(encoding='utf-8') if source.exists() else ''
for token in ['ServiceBase.Run','requests','results','history','--dry-run','--run-once','--confirm-preview','CancellationToken','ProcessJob.CreateKillOnClose','AssignProcessToJobObject','CreateSuspended','ResumeThread','TerminateJobObject','worker_build_sha256','executor_sid','sync_tool_build_sha256','File.Exists(toolAssembly) ? toolAssembly : executable','preview_id','preview_output_malformed','PreviewIdMalformed','WriteHeartbeat("stopped", null)','service_or_cli_start_failed','TryGetProperty("previewId"','"addCount"','direction','ArgumentList','hStdError','requested_by','Path.Combine(root, "results", "service-status.json")','TimeSpan.FromDays(90)','nextPruneAt','pruneInterval','--prune-interval-seconds=','--run-seconds=','WriteHeartbeat("running"','--once','FileAttributes.ReparsePoint','AtomicWrite','aborted_unverified']:
    assert token in text, f'missing service contract: {token}'
print('SYNCTOOL_SERVICE_CONTRACT_OK')
