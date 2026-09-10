# Names the window that owns a given screen pixel, and lists every window
# EzBizi owns.
#
# Written 2026-09-10 for the ultrawide report: with EzBizi snapped to the right
# half, the left half of the desktop stops accepting clicks, and killing
# EzBizi.exe restores it. That rules out system state and means a live window of
# ours is over that pixel. This says WHICH one, instead of inferring it.
#
#   powershell -ExecutionPolicy Bypass -File who-eats-the-click.ps1
#   powershell -ExecutionPolicy Bypass -File who-eats-the-click.ps1 -X 300 -Y 600
#
# Run it WHILE the left side is dead. Point X,Y at a spot that will not click.

param(
  [int]$X = -1,
  [int]$Y = -1
)

Add-Type @'
using System;
using System.Text;
using System.Runtime.InteropServices;
public class Probe {
  public delegate bool EnumProc(IntPtr h, IntPtr l);
  [DllImport("user32.dll")] public static extern bool EnumWindows(EnumProc cb, IntPtr l);
  [DllImport("user32.dll")] public static extern IntPtr WindowFromPoint(POINT p);
  [DllImport("user32.dll")] public static extern IntPtr GetAncestor(IntPtr h, uint flags);
  [DllImport("user32.dll")] public static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
  [DllImport("user32.dll")] public static extern bool IsWindowVisible(IntPtr h);
  [DllImport("user32.dll")] public static extern bool GetWindowRect(IntPtr h, out RECT r);
  [DllImport("user32.dll")] public static extern int GetClassName(IntPtr h, StringBuilder s, int m);
  [DllImport("user32.dll")] public static extern int GetWindowText(IntPtr h, StringBuilder s, int m);
  [DllImport("user32.dll", EntryPoint="GetWindowLongPtrW")] public static extern IntPtr GetWindowLongPtr(IntPtr h, int i);
  [DllImport("user32.dll")] public static extern bool SetProcessDPIAware();
  [DllImport("user32.dll")] public static extern int GetSystemMetrics(int i);
  [StructLayout(LayoutKind.Sequential)] public struct POINT { public int X, Y; }
  [StructLayout(LayoutKind.Sequential)] public struct RECT { public int L, T, R, B; }
}
'@

# Physical pixels, or the coordinates will not match what the mouse hits.
[Probe]::SetProcessDPIAware() | Out-Null

# The whole desktop, including monitors left of the primary (negative X).
$vx = [Probe]::GetSystemMetrics(76)
$vy = [Probe]::GetSystemMetrics(77)
$vw = [Probe]::GetSystemMetrics(78)
$vh = [Probe]::GetSystemMetrics(79)
Write-Output ("virtual desktop: {0},{1} to {2},{3}  ({4} x {5})" -f $vx, $vy, ($vx + $vw), ($vy + $vh), $vw, $vh)

if ($X -lt 0 -and $Y -lt 0) { $X = $vx + 200; $Y = $vy + [int]($vh / 2) }
Write-Output ("probing pixel: {0},{1}`n" -f $X, $Y)

function Describe($h, $label) {
  if ($h -eq [IntPtr]::Zero) { Write-Output "$label : (no window)"; return }
  $procId = 0
  [Probe]::GetWindowThreadProcessId($h, [ref]$procId) | Out-Null
  $name = try { (Get-Process -Id $procId -ErrorAction Stop).ProcessName } catch { "(gone)" }
  $cls = New-Object System.Text.StringBuilder 256
  [Probe]::GetClassName($h, $cls, 256) | Out-Null
  $txt = New-Object System.Text.StringBuilder 256
  [Probe]::GetWindowText($h, $txt, 256) | Out-Null
  $r = New-Object Probe+RECT
  [Probe]::GetWindowRect($h, [ref]$r) | Out-Null
  $ex = [Probe]::GetWindowLongPtr($h, -20).ToInt64()
  $st = [Probe]::GetWindowLongPtr($h, -16).ToInt64()
  $flags = @()
  if ($ex -band 0x00000020) { $flags += "TRANSPARENT(click-through)" }
  if ($ex -band 0x00080000) { $flags += "LAYERED" }
  if ($ex -band 0x00000008) { $flags += "TOPMOST" }
  if ($ex -band 0x00000080) { $flags += "TOOLWINDOW" }
  Write-Output ("$label :")
  Write-Output ("    process   {0} (pid {1})" -f $name, $procId)
  Write-Output ("    class     {0}" -f $cls.ToString())
  Write-Output ("    title     {0}" -f $txt.ToString())
  Write-Output ("    rect      {0},{1} to {2},{3}   size {4} x {5}" -f $r.L, $r.T, $r.R, $r.B, ($r.R - $r.L), ($r.B - $r.T))
  Write-Output ("    visible   {0}" -f [Probe]::IsWindowVisible($h))
  Write-Output ("    style     0x{0:X}   exstyle 0x{1:X}   {2}" -f $st, $ex, ($flags -join " "))
  Write-Output ""
}

$p = New-Object Probe+POINT
$p.X = $X; $p.Y = $Y
$hit = [Probe]::WindowFromPoint($p)
Describe $hit "THE WINDOW THAT OWNS THAT PIXEL"
$root = [Probe]::GetAncestor($hit, 2)
if ($root -ne $hit) { Describe $root "  its top-level parent" }

Write-Output "EVERY WINDOW EZBIZI OWNS:"
$ez = @(Get-Process -Name ezbizi -ErrorAction SilentlyContinue | ForEach-Object { $_.Id })
if ($ez.Count -eq 0) { Write-Output "  ezbizi.exe is not running"; return }
$rows = @()
$cb = [Probe+EnumProc]{
  param($h, $l)
  $procId = 0
  [Probe]::GetWindowThreadProcessId($h, [ref]$procId) | Out-Null
  if ($ez -contains $procId) {
    $cls = New-Object System.Text.StringBuilder 256
    [Probe]::GetClassName($h, $cls, 256) | Out-Null
    $r = New-Object Probe+RECT
    [Probe]::GetWindowRect($h, [ref]$r) | Out-Null
    $ex = [Probe]::GetWindowLongPtr($h, -20).ToInt64()
    $script:rows += [PSCustomObject]@{
      Handle  = ('0x{0:X}' -f $h.ToInt64())
      Vis     = [Probe]::IsWindowVisible($h)
      Class   = $cls.ToString()
      Rect    = "$($r.L),$($r.T) to $($r.R),$($r.B)"
      Size    = "$($r.R - $r.L) x $($r.B - $r.T)"
      ExStyle = ('0x{0:X}' -f $ex)
    }
  }
  return $true
}
[Probe]::EnumWindows($cb, [IntPtr]::Zero) | Out-Null
$rows | Format-Table -AutoSize | Out-String -Width 200 | Write-Output
