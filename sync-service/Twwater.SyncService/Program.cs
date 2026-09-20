using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Security.Principal;
using System.ServiceProcess;
using System.Text;
using System.Text.Json;
using Microsoft.Win32.SafeHandles;

if (Environment.UserInteractive)
{
    var once = args.Contains("--once", StringComparer.Ordinal);
    var rootArgument = args.FirstOrDefault(value => value.StartsWith("--root=", StringComparison.Ordinal));
    var pruneSeconds = ReadBoundedIntArgument(args, "--prune-interval-seconds=", 1, 86400);
    var runSeconds = ReadBoundedIntArgument(args, "--run-seconds=", 1, 300);
    var root = rootArgument is null ? ServiceSettings.ProductionRoot : rootArgument["--root=".Length..];
    using var cancellation = new CancellationTokenSource();
    if (runSeconds is not null) cancellation.CancelAfter(TimeSpan.FromSeconds(runSeconds.Value));
    try { await new SyncWorker(root, once, TimeSpan.FromSeconds(pruneSeconds ?? 3600)).Run(cancellation.Token); }
    catch (OperationCanceledException) when (runSeconds is not null && cancellation.IsCancellationRequested) { }
}
else
{
    ServiceBase.Run(new TwwaterService());
}

static int? ReadBoundedIntArgument(string[] values, string prefix, int minimum, int maximum)
{
    var raw = values.FirstOrDefault(value => value.StartsWith(prefix, StringComparison.Ordinal));
    if (raw is null) return null;
    if (!int.TryParse(raw[prefix.Length..], out var parsed) || parsed < minimum || parsed > maximum)
        throw new InvalidOperationException("invalid_interactive_argument");
    return parsed;
}

sealed class TwwaterService : ServiceBase
{
    private readonly CancellationTokenSource cancellation = new();
    private Task? worker;

    public TwwaterService() => ServiceName = ServiceSettings.ServiceNameValue;
    protected override void OnStart(string[] args)
    {
        worker = new SyncWorker(ServiceSettings.ProductionRoot, false).Run(cancellation.Token);
        _ = worker.ContinueWith(
            task => Environment.FailFast("sync_worker_faulted", task.Exception),
            CancellationToken.None,
            TaskContinuationOptions.OnlyOnFaulted | TaskContinuationOptions.ExecuteSynchronously,
            TaskScheduler.Default);
    }
    protected override void OnStop()
    {
        cancellation.Cancel();
        try
        {
            if (worker is not null && !worker.Wait(TimeSpan.FromSeconds(30)))
                Environment.FailFast("sync_worker_stop_timeout");
        }
        catch (AggregateException error) when (error.InnerExceptions.All(item => item is OperationCanceledException)) { }
    }
}

sealed class SyncWorker
{
    private static readonly string[] Directions = ["drive_to_library", "library_to_drive", "two_way"];
    private static readonly string[] DirectoryNames = ["requests", "results", "history", "cancel", "service"];
    private static readonly string WorkerBuildSha256 = HashFile(typeof(SyncWorker).Assembly.Location);
    private static readonly string ExecutorSid = WindowsIdentity.GetCurrent().User?.Value ?? throw new InvalidOperationException("executor_sid_unavailable");
    private readonly string root;
    private readonly bool once;
    private readonly TimeSpan pruneInterval;

    public SyncWorker(string root, bool once, TimeSpan? pruneInterval = null)
    {
        if (!Path.IsPathFullyQualified(root)) throw new InvalidOperationException("invalid_runtime_root");
        this.root = Path.GetFullPath(root);
        this.once = once;
        this.pruneInterval = pruneInterval ?? TimeSpan.FromHours(1);
    }

    public async Task Run(CancellationToken token)
    {
        EnsureRuntime();
        PruneExpiredFiles();
        var nextPruneAt = DateTimeOffset.UtcNow.Add(pruneInterval);
        try
        {
            do
            {
                if (DateTimeOffset.UtcNow >= nextPruneAt)
                {
                    PruneExpiredFiles();
                    nextPruneAt = DateTimeOffset.UtcNow.Add(pruneInterval);
                }
                await WriteHeartbeat("ready", null);
                foreach (var path in Directory.EnumerateFiles(Path.Combine(root, "requests"), "*.json").OrderBy(value => value, StringComparer.OrdinalIgnoreCase))
                {
                    token.ThrowIfCancellationRequested();
                    try { await Handle(path, token); }
                    catch (OperationCanceledException) when (token.IsCancellationRequested) { throw; }
                    catch
                    {
                        var id = Path.GetFileNameWithoutExtension(path);
                        if (IsId(id))
                        {
                            var fallback = new Request(id, "unknown", "unknown", "", 0);
                            await WriteResult(fallback, "failed", SyncOutcome.Invalid, "service_error");
                        }
                        SafeDelete(path);
                    }
                }
                if (once) break;
                await Task.Delay(1000, token);
            } while (!token.IsCancellationRequested);
        }
        finally
        {
            await WriteHeartbeat("stopped", null);
        }
    }

    private void EnsureRuntime()
    {
        if (Directory.Exists(root) && HasReparsePoint(root)) throw new InvalidOperationException("runtime_root_reparse_point");
        Directory.CreateDirectory(root);
        foreach (var name in DirectoryNames)
        {
            var path = Path.Combine(root, name);
            Directory.CreateDirectory(path);
            if (HasReparsePoint(path)) throw new InvalidOperationException("runtime_directory_reparse_point");
        }
    }

    private async Task Handle(string requestPath, CancellationToken token)
    {
        if (HasReparsePoint(requestPath)) throw new InvalidOperationException("request_reparse_point");
        var request = await ReadRequest(requestPath, token);
        var existing = await ReadExistingStatus(request.Id, token);
        if (existing is not null)
        {
            if (existing == "running") await WriteResult(request, "aborted_unverified", SyncOutcome.Invalid, "interrupted_before_verification");
            SafeDelete(requestPath);
            return;
        }

        var cancelPath = Path.Combine(root, "cancel", request.Id + ".cancel");
        if (File.Exists(cancelPath))
        {
            await WriteResult(request, "aborted_unverified", SyncOutcome.Invalid, "cancelled_before_start");
            SafeDelete(cancelPath);
            SafeDelete(requestPath);
            return;
        }

        await WriteResult(request, "running", SyncOutcome.Invalid, null);
        await WriteHeartbeat("running", request.Id);
        SuspendedProcess? child = null;
        Process? process = null;
        ProcessJob? job = null;
        ServiceConfig? config = null;
        var processStarted = false;
        try
        {
            config = await ReadConfig(token);
            job = ProcessJob.CreateKillOnClose();
            child = SuspendedProcess.Start(BuildProcess(config, request), job, Path.Combine(root, "history"));
            process = child.Process;
            processStarted = true;
            var nextHeartbeatAt = DateTimeOffset.UtcNow;
            while (!process.HasExited)
            {
                if (DateTimeOffset.UtcNow >= nextHeartbeatAt)
                {
                    await WriteHeartbeat("running", request.Id);
                    nextHeartbeatAt = DateTimeOffset.UtcNow.AddSeconds(5);
                }
                if (File.Exists(cancelPath))
                {
                    if (!await StopProcessTree(process, job))
                    {
                        await WriteResult(request, "aborted_unverified", SyncOutcome.Invalid, "termination_failed");
                        SafeDelete(requestPath);
                        Environment.FailFast("sync_tool_tree_termination_failed");
                    }
                    await WriteResult(request, "aborted_unverified", SyncOutcome.Invalid, "forced_termination");
                    SafeDelete(cancelPath);
                    SafeDelete(requestPath);
                    return;
                }
                token.ThrowIfCancellationRequested();
                await Task.Delay(250, token);
            }

            var output = await child.ReadStandardOutput();
            var exitCode = child.ExitCode;
            var outcome = exitCode == 0 ? ExtractOutcome(output) : SyncOutcome.Invalid;
            var previewMatches = request.Kind == "preview"
                ? !outcome.PreviewIdMalformed && outcome.PreviewId is not null
                : !outcome.PreviewIdMalformed && (outcome.PreviewId is null || outcome.PreviewId == request.PreviewId);
            var status = exitCode != 0 || !outcome.ValidMetrics || !previewMatches
                ? "failed"
                : request.Kind == "preview" ? "preview_ready" : outcome.HasAttention ? "completed_with_attention" : "success";
            var errorCode = status != "failed"
                ? null
                : outcome.PreviewIdMalformed
                    ? "preview_output_malformed"
                : request.Kind == "execute" && outcome.ValidMetrics && outcome.PreviewId is not null && outcome.PreviewId != request.PreviewId
                    ? "preview_output_mismatch"
                    : "cli_failed_or_invalid_output";
            await WriteResult(request, status, outcome, errorCode, config.ToolBuildSha256);
            SafeDelete(requestPath);
        }
        catch (OperationCanceledException) when (token.IsCancellationRequested)
        {
            if (processStarted && process is not null && job is not null && !await StopProcessTree(process, job))
            {
                await WriteResult(request, "aborted_unverified", SyncOutcome.Invalid, "termination_failed");
                SafeDelete(requestPath);
                Environment.FailFast("sync_tool_tree_termination_failed");
            }
            await WriteResult(request, "aborted_unverified", SyncOutcome.Invalid, "service_stopped", config?.ToolBuildSha256);
            SafeDelete(requestPath);
            throw;
        }
        catch (Exception)
        {
            if (processStarted && process is not null && job is not null && !await StopProcessTree(process, job))
            {
                await WriteResult(request, "aborted_unverified", SyncOutcome.Invalid, "termination_failed");
                SafeDelete(requestPath);
                Environment.FailFast("sync_tool_tree_termination_failed");
            }
            await WriteResult(request, "failed", SyncOutcome.Invalid, "service_or_cli_start_failed", config?.ToolBuildSha256);
            SafeDelete(requestPath);
        }
        finally
        {
            job?.Dispose();
            child?.Dispose();
            if (!token.IsCancellationRequested) await WriteHeartbeat("ready", null);
        }
    }

    private ProcessStartInfo BuildProcess(ServiceConfig config, Request request)
    {
        var startInfo = new ProcessStartInfo(config.Executable)
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            WorkingDirectory = Path.GetDirectoryName(config.Executable) ?? root,
        };
        startInfo.ArgumentList.Add(request.Kind == "preview" ? "--dry-run" : "--run-once");
        startInfo.ArgumentList.Add($"--config={config.SyncConfig}");
        startInfo.ArgumentList.Add($"--job={config.JobId}");
        startInfo.ArgumentList.Add($"--mode={DirectionToMode(request.Direction)}");
        if (request.Kind == "execute") startInfo.ArgumentList.Add($"--confirm-preview={request.PreviewId}");
        return startInfo;
    }

    private async Task<Request> ReadRequest(string requestPath, CancellationToken token)
    {
        using var document = JsonDocument.Parse(await File.ReadAllTextAsync(requestPath, token));
        var value = document.RootElement;
        if (value.ValueKind != JsonValueKind.Object || !value.TryGetProperty("schema_version", out var version) || version.GetInt32() != 1)
            throw new InvalidOperationException("invalid_request_schema");
        var id = value.GetProperty("request_id").GetString() ?? "";
        var kind = value.GetProperty("kind").GetString() ?? "";
        var direction = value.GetProperty("direction").GetString() ?? "";
        var previewId = value.GetProperty("preview_id").GetString() ?? "";
        var requestedBy = value.GetProperty("requested_by").GetInt32();
        if (Path.GetFileNameWithoutExtension(requestPath) != id || !IsId(id) || (kind != "preview" && kind != "execute") || !Directions.Contains(direction) || requestedBy <= 0 || (kind == "execute" && !IsId(previewId)))
            throw new InvalidOperationException("invalid_request_fields");
        return new Request(id, kind, direction, previewId, requestedBy);
    }

    private async Task<ServiceConfig> ReadConfig(CancellationToken token)
    {
        var path = Path.Combine(root, "service", "service-config.json");
        if (HasReparsePoint(path)) throw new InvalidOperationException("service_config_reparse_point");
        using var document = JsonDocument.Parse(await File.ReadAllTextAsync(path, token));
        var value = document.RootElement;
        if (value.ValueKind != JsonValueKind.Object || !value.TryGetProperty("schema_version", out var version) || version.GetInt32() != 1)
            throw new InvalidOperationException("invalid_service_config");
        var executable = value.GetProperty("sync_tool_exe").GetString() ?? "";
        var syncConfig = value.GetProperty("sync_tool_config").GetString() ?? "";
        var jobId = value.GetProperty("job_id").GetString() ?? "";
        var toolAssembly = Path.ChangeExtension(executable, ".dll");
        var toolBuildArtifact = File.Exists(toolAssembly) ? toolAssembly : executable;
        if (!Path.IsPathFullyQualified(executable) || !File.Exists(executable) || HasReparsePoint(executable) || HasReparsePoint(toolBuildArtifact) || !Path.IsPathFullyQualified(syncConfig) || !File.Exists(syncConfig) || HasReparsePoint(syncConfig) || string.IsNullOrWhiteSpace(jobId))
            throw new InvalidOperationException("invalid_service_config");
        return new ServiceConfig(executable, syncConfig, jobId, HashFile(toolBuildArtifact));
    }

    private async Task<string?> ReadExistingStatus(string id, CancellationToken token)
    {
        var path = Path.Combine(root, "results", id + ".json");
        if (!File.Exists(path)) return null;
        using var document = JsonDocument.Parse(await File.ReadAllTextAsync(path, token));
        return document.RootElement.TryGetProperty("status", out var status) ? status.GetString() : "invalid";
    }

    private async Task WriteResult(Request request, string status, SyncOutcome outcome, string? errorCode, string? syncToolBuildSha256 = null)
    {
        var previewId = request.Kind == "execute" ? request.PreviewId : outcome.PreviewId;
        var value = JsonSerializer.Serialize(new
        {
            schema_version = 1,
            request_id = request.Id,
            kind = request.Kind,
            direction = request.Direction,
            status,
            preview_id = string.IsNullOrEmpty(previewId) ? null : previewId,
            output_preview_id = outcome.PreviewId,
            requested_by = request.RequestedBy,
            add_count = outcome.ValidMetrics ? outcome.AddCount : 0,
            update_count = outcome.ValidMetrics ? outcome.UpdateCount : 0,
            delete_count = outcome.ValidMetrics ? outcome.DeleteCount : 0,
            conflict_count = outcome.ValidMetrics ? outcome.ConflictCount : 0,
            access_denied_count = outcome.ValidMetrics ? outcome.AccessDeniedCount : 0,
            metrics_recorded = outcome.ValidMetrics,
            error_code = errorCode,
            worker_build_sha256 = WorkerBuildSha256,
            executor_sid = ExecutorSid,
            sync_tool_build_sha256 = syncToolBuildSha256,
            updated_at = DateTimeOffset.UtcNow,
        });
        await AtomicWrite(Path.Combine(root, "results", request.Id + ".json"), value);
        await File.AppendAllTextAsync(Path.Combine(root, "history", DateTime.UtcNow.ToString("yyyyMMdd") + ".jsonl"), value + Environment.NewLine);
    }

    private async Task WriteHeartbeat(string status, string? requestId)
    {
        var value = JsonSerializer.Serialize(new { schema_version = 1, status, request_id = requestId, worker_build_sha256 = WorkerBuildSha256, executor_sid = ExecutorSid, updated_at = DateTimeOffset.UtcNow });
        await AtomicWrite(Path.Combine(root, "results", "service-status.json"), value);
    }

    private static async Task AtomicWrite(string finalPath, string content)
    {
        var staging = finalPath + "." + Guid.NewGuid().ToString("N") + ".pending";
        try
        {
            await File.WriteAllTextAsync(staging, content);
            File.Move(staging, finalPath, true);
        }
        finally { SafeDelete(staging); }
    }

    private void PruneExpiredFiles()
    {
        var cutoff = DateTime.UtcNow - TimeSpan.FromDays(90);
        foreach (var directory in new[] { "results", "history", "cancel" })
            foreach (var path in Directory.EnumerateFiles(Path.Combine(root, directory)))
                if (File.GetLastWriteTimeUtc(path) < cutoff && !HasReparsePoint(path)) SafeDelete(path);
    }

    private static string DirectionToMode(string direction) => direction switch
    {
        "drive_to_library" => "b-to-a",
        "library_to_drive" => "a-to-b",
        "two_way" => "two-way",
        _ => throw new InvalidOperationException("invalid_direction"),
    };

    private static SyncOutcome ExtractOutcome(string output)
    {
        try
        {
            using var document = JsonDocument.Parse(output);
            var value = document.RootElement;
            string? previewId = null;
            var previewIdMalformed = false;
            if (value.TryGetProperty("previewId", out var preview) && preview.ValueKind is not JsonValueKind.Null and not JsonValueKind.Undefined)
            {
                if (preview.ValueKind == JsonValueKind.String)
                {
                    var candidate = preview.GetString();
                    if (candidate is not null && IsId(candidate)) previewId = candidate;
                    else previewIdMalformed = true;
                }
                else previewIdMalformed = true;
            }
            var add = 0; var update = 0; var delete = 0; var conflict = 0; var denied = 0;
            var valid = TryCount(value, "addCount", out add) && TryCount(value, "updateCount", out update) && TryCount(value, "deleteCount", out delete) && TryCount(value, "conflictCount", out conflict) && TryCount(value, "accessDeniedCount", out denied);
            return new SyncOutcome(previewId, previewIdMalformed, add, update, delete, conflict, denied, valid);
        }
        catch (Exception error) when (error is JsonException or InvalidOperationException) { return SyncOutcome.Invalid; }
    }

    private static bool TryCount(JsonElement value, string name, out int count)
    {
        count = 0;
        return value.TryGetProperty(name, out var property) && property.TryGetInt32(out count) && count >= 0;
    }

    private static bool HasReparsePoint(string path)
    {
        var current = Path.GetFullPath(path);
        while (!string.IsNullOrEmpty(current))
        {
            if ((File.GetAttributes(current) & FileAttributes.ReparsePoint) != 0) return true;
            var parent = Path.GetDirectoryName(current);
            if (parent == current || parent is null) break;
            current = parent;
        }
        return false;
    }

    private static async Task<bool> StopProcessTree(Process process, ProcessJob job)
    {
        try
        {
            if (process.HasExited) return true;
            job.Terminate();
            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(10));
            await process.WaitForExitAsync(timeout.Token);
            return process.HasExited;
        }
        catch (Exception error) when (error is InvalidOperationException or Win32Exception or NotSupportedException or OperationCanceledException)
        {
            return false;
        }
    }

    private static void SafeDelete(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); } catch (IOException) { }
    }

    private static bool IsId(string value) => value.Length == 32 && value.All(character => character is >= 'a' and <= 'f' or >= '0' and <= '9');
    private static string HashFile(string path)
    {
        using var stream = File.OpenRead(path);
        return Convert.ToHexString(SHA256.HashData(stream)).ToLowerInvariant();
    }
    private sealed record Request(string Id, string Kind, string Direction, string PreviewId, int RequestedBy);
    private sealed record ServiceConfig(string Executable, string SyncConfig, string JobId, string ToolBuildSha256);
    private sealed record SyncOutcome(string? PreviewId, bool PreviewIdMalformed, int AddCount, int UpdateCount, int DeleteCount, int ConflictCount, int AccessDeniedCount, bool ValidMetrics)
    {
        public static readonly SyncOutcome Invalid = new(null, false, 0, 0, 0, 0, 0, false);
        public bool HasAttention => ConflictCount > 0 || AccessDeniedCount > 0;
    }
}

sealed class SuspendedProcess : IDisposable
{
    private const uint CreateSuspended = 0x00000004;
    private const uint CreateNoWindow = 0x08000000;
    private const uint StartfUseStdHandles = 0x00000100;
    private const uint HandleFlagInherit = 0x00000001;
    private readonly FileStream stdin;
    private readonly FileStream stdout;
    private readonly FileStream stderr;
    private readonly SafeFileHandle nativeProcessHandle;
    private readonly string stdoutPath;
    private readonly string stderrPath;
    private bool disposed;

    private SuspendedProcess(Process process, SafeFileHandle nativeProcessHandle, FileStream stdin, FileStream stdout, FileStream stderr, string stdoutPath, string stderrPath)
    {
        Process = process;
        this.nativeProcessHandle = nativeProcessHandle;
        this.stdin = stdin;
        this.stdout = stdout;
        this.stderr = stderr;
        this.stdoutPath = stdoutPath;
        this.stderrPath = stderrPath;
    }

    public Process Process { get; }
    public int ExitCode
    {
        get
        {
            if (!GetExitCodeProcess(nativeProcessHandle, out var exitCode))
                throw new Win32Exception(Marshal.GetLastWin32Error(), "sync_tool_exit_code_failed");
            return unchecked((int) exitCode);
        }
    }

    public static SuspendedProcess Start(ProcessStartInfo startInfo, ProcessJob job, string scratchDirectory)
    {
        Directory.CreateDirectory(scratchDirectory);
        var token = Guid.NewGuid().ToString("N");
        var stdoutPath = Path.Combine(scratchDirectory, token + ".stdout.pending");
        var stderrPath = Path.Combine(scratchDirectory, token + ".stderr.pending");
        FileStream? stdin = null;
        FileStream? stdout = null;
        FileStream? stderr = null;
        ProcessInformation information = default;
        try
        {
            stdin = OpenInheritableInput();
            stdout = OpenInheritableOutput(stdoutPath);
            stderr = OpenInheritableOutput(stderrPath);
            var startup = new StartupInformation
            {
                cb = Marshal.SizeOf<StartupInformation>(),
                dwFlags = StartfUseStdHandles,
                hStdOutput = stdout.SafeFileHandle.DangerousGetHandle(),
                hStdError = stderr.SafeFileHandle.DangerousGetHandle(),
                hStdInput = stdin.SafeFileHandle.DangerousGetHandle(),
            };
            var commandLine = new StringBuilder(BuildCommandLine(startInfo));
            if (!CreateProcessW(null, commandLine, IntPtr.Zero, IntPtr.Zero, true, CreateSuspended | CreateNoWindow, IntPtr.Zero, startInfo.WorkingDirectory, ref startup, out information))
                throw new Win32Exception(Marshal.GetLastWin32Error(), "sync_tool_start_failed");
            try
            {
                job.Assign(information.hProcess);
                if (ResumeThread(information.hThread) == uint.MaxValue)
                    throw new Win32Exception(Marshal.GetLastWin32Error(), "sync_tool_resume_failed");
                var process = Process.GetProcessById((int) information.dwProcessId);
                var nativeProcessHandle = new SafeFileHandle(information.hProcess, true);
                information.hProcess = IntPtr.Zero;
                return new SuspendedProcess(process, nativeProcessHandle, stdin, stdout, stderr, stdoutPath, stderrPath);
            }
            catch
            {
                TerminateProcess(information.hProcess, 1);
                throw;
            }
            finally
            {
                if (information.hThread != IntPtr.Zero) CloseHandle(information.hThread);
                if (information.hProcess != IntPtr.Zero) CloseHandle(information.hProcess);
            }
        }
        catch
        {
            stdin?.Dispose();
            stdout?.Dispose();
            stderr?.Dispose();
            SafeDeleteFile(stdoutPath);
            SafeDeleteFile(stderrPath);
            throw;
        }
    }

    public async Task<string> ReadStandardOutput()
    {
        stdin.Dispose();
        stdout.Flush(true);
        stderr.Flush(true);
        stdout.Dispose();
        stderr.Dispose();
        return await File.ReadAllTextAsync(stdoutPath);
    }

    public void Dispose()
    {
        if (disposed) return;
        disposed = true;
        stdin.Dispose();
        stdout.Dispose();
        stderr.Dispose();
        Process.Dispose();
        nativeProcessHandle.Dispose();
        SafeDeleteFile(stdoutPath);
        SafeDeleteFile(stderrPath);
    }

    private static FileStream OpenInheritableOutput(string path)
    {
        var stream = new FileStream(path, FileMode.CreateNew, FileAccess.Write, FileShare.Read | FileShare.Delete, 4096, FileOptions.WriteThrough);
        if (!SetHandleInformation(stream.SafeFileHandle, HandleFlagInherit, HandleFlagInherit))
        {
            stream.Dispose();
            throw new Win32Exception(Marshal.GetLastWin32Error(), "output_handle_inheritance_failed");
        }
        return stream;
    }

    private static FileStream OpenInheritableInput()
    {
        var stream = new FileStream("NUL", FileMode.Open, FileAccess.Read, FileShare.ReadWrite);
        if (!SetHandleInformation(stream.SafeFileHandle, HandleFlagInherit, HandleFlagInherit))
        {
            stream.Dispose();
            throw new Win32Exception(Marshal.GetLastWin32Error(), "input_handle_inheritance_failed");
        }
        return stream;
    }

    private static string BuildCommandLine(ProcessStartInfo startInfo)
    {
        var values = new List<string> { startInfo.FileName };
        values.AddRange(startInfo.ArgumentList);
        return string.Join(" ", values.Select(QuoteArgument));
    }

    private static string QuoteArgument(string value)
    {
        if (value.Length > 0 && !value.Any(character => char.IsWhiteSpace(character) || character == '"')) return value;
        var output = new StringBuilder("\"");
        var backslashes = 0;
        foreach (var character in value)
        {
            if (character == '\\') { backslashes++; continue; }
            if (character == '"')
            {
                output.Append('\\', backslashes * 2 + 1).Append('"');
                backslashes = 0;
                continue;
            }
            output.Append('\\', backslashes).Append(character);
            backslashes = 0;
        }
        output.Append('\\', backslashes * 2).Append('"');
        return output.ToString();
    }

    private static void SafeDeleteFile(string path)
    {
        try { if (File.Exists(path)) File.Delete(path); } catch (IOException) { }
    }

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern bool CreateProcessW(string? applicationName, StringBuilder commandLine, IntPtr processAttributes, IntPtr threadAttributes, bool inheritHandles, uint creationFlags, IntPtr environment, string? currentDirectory, ref StartupInformation startupInfo, out ProcessInformation processInformation);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern uint ResumeThread(IntPtr thread);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool TerminateProcess(IntPtr process, uint exitCode);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool GetExitCodeProcess(SafeFileHandle process, out uint exitCode);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool CloseHandle(IntPtr handle);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool SetHandleInformation(SafeFileHandle handle, uint mask, uint flags);

    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    private struct StartupInformation
    {
        public int cb;
        public string? lpReserved;
        public string? lpDesktop;
        public string? lpTitle;
        public int dwX;
        public int dwY;
        public int dwXSize;
        public int dwYSize;
        public int dwXCountChars;
        public int dwYCountChars;
        public int dwFillAttribute;
        public uint dwFlags;
        public short wShowWindow;
        public short cbReserved2;
        public IntPtr lpReserved2;
        public IntPtr hStdInput;
        public IntPtr hStdOutput;
        public IntPtr hStdError;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct ProcessInformation
    {
        public IntPtr hProcess;
        public IntPtr hThread;
        public uint dwProcessId;
        public uint dwThreadId;
    }
}

sealed class ProcessJob : IDisposable
{
    private const uint KillOnJobClose = 0x00002000;
    private readonly SafeFileHandle handle;

    private ProcessJob(SafeFileHandle handle) => this.handle = handle;

    public static ProcessJob CreateKillOnClose()
    {
        var handle = CreateJobObject(IntPtr.Zero, null);
        if (handle.IsInvalid) throw new Win32Exception(Marshal.GetLastWin32Error(), "create_job_failed");
        var job = new ProcessJob(handle);
        var information = new JobObjectExtendedLimitInformation
        {
            BasicLimitInformation = new JobObjectBasicLimitInformation { LimitFlags = KillOnJobClose },
        };
        var length = Marshal.SizeOf<JobObjectExtendedLimitInformation>();
        var pointer = Marshal.AllocHGlobal(length);
        try
        {
            Marshal.StructureToPtr(information, pointer, false);
            if (!SetInformationJobObject(handle, 9, pointer, (uint) length))
                throw new Win32Exception(Marshal.GetLastWin32Error(), "configure_job_failed");
        }
        catch
        {
            job.Dispose();
            throw;
        }
        finally { Marshal.FreeHGlobal(pointer); }
        return job;
    }

    public void Assign(IntPtr processHandle)
    {
        if (AssignProcessToJobObject(handle, processHandle)) return;
        throw new Win32Exception(Marshal.GetLastWin32Error(), "assign_job_failed");
    }

    public void Terminate()
    {
        if (!TerminateJobObject(handle, 1))
            throw new Win32Exception(Marshal.GetLastWin32Error(), "terminate_job_failed");
    }

    public void Dispose() => handle.Dispose();

    [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
    private static extern SafeFileHandle CreateJobObject(IntPtr jobAttributes, string? name);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool SetInformationJobObject(SafeFileHandle job, int infoClass, IntPtr info, uint length);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool AssignProcessToJobObject(SafeFileHandle job, IntPtr process);
    [DllImport("kernel32.dll", SetLastError = true)]
    private static extern bool TerminateJobObject(SafeFileHandle job, uint exitCode);

    [StructLayout(LayoutKind.Sequential)]
    private struct JobObjectBasicLimitInformation
    {
        public long PerProcessUserTimeLimit;
        public long PerJobUserTimeLimit;
        public uint LimitFlags;
        public UIntPtr MinimumWorkingSetSize;
        public UIntPtr MaximumWorkingSetSize;
        public uint ActiveProcessLimit;
        public UIntPtr Affinity;
        public uint PriorityClass;
        public uint SchedulingClass;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct IoCounters
    {
        public ulong ReadOperationCount;
        public ulong WriteOperationCount;
        public ulong OtherOperationCount;
        public ulong ReadTransferCount;
        public ulong WriteTransferCount;
        public ulong OtherTransferCount;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct JobObjectExtendedLimitInformation
    {
        public JobObjectBasicLimitInformation BasicLimitInformation;
        public IoCounters IoInfo;
        public UIntPtr ProcessMemoryLimit;
        public UIntPtr JobMemoryLimit;
        public UIntPtr PeakProcessMemoryUsed;
        public UIntPtr PeakJobMemoryUsed;
    }
}

static class ServiceSettings
{
    public const string ServiceNameValue = "TWWATER SyncTool Service";
    public const string ProductionRoot = @"C:\TWWATER\runtime\sync-service";
}
