using System.Diagnostics;
using System.Text.Json;

var childArgument = args.FirstOrDefault(value => value.StartsWith("--child-sleep=", StringComparison.Ordinal));
if (childArgument is not null)
{
    var seconds = int.Parse(childArgument["--child-sleep=".Length..]);
    await Task.Delay(TimeSpan.FromSeconds(seconds));
    return;
}

var configArgument = args.FirstOrDefault(value => value.StartsWith("--config=", StringComparison.Ordinal));
if (configArgument is null) return;
var configPath = configArgument["--config=".Length..];
using var document = JsonDocument.Parse(await File.ReadAllTextAsync(configPath));
var root = document.RootElement;
var sleepSeconds = root.TryGetProperty("sleep_seconds", out var sleep) ? sleep.GetInt32() : 0;
var outputPreviewId = root.TryGetProperty("output_preview_id", out var preview) && preview.ValueKind == JsonValueKind.String ? preview.GetString() : null;
var spawnChild = root.TryGetProperty("spawn_child", out var spawn) && spawn.GetBoolean();

if (spawnChild)
{
    var child = Process.Start(new ProcessStartInfo(Environment.ProcessPath!, $"--child-sleep={Math.Max(1, sleepSeconds)}")
    {
        UseShellExecute = false,
        CreateNoWindow = true,
    }) ?? throw new InvalidOperationException("fake_child_start_failed");
    var childPidFile = root.GetProperty("child_pid_file").GetString() ?? throw new InvalidOperationException("fake_child_pid_path_missing");
    await File.WriteAllTextAsync(childPidFile, child.Id.ToString());
}

if (sleepSeconds > 0) await Task.Delay(TimeSpan.FromSeconds(sleepSeconds));
Console.Out.Write(JsonSerializer.Serialize(new
{
    status = "success",
    previewId = outputPreviewId,
    addCount = 0,
    updateCount = 0,
    deleteCount = 0,
    conflictCount = 0,
    accessDeniedCount = 0,
}));
