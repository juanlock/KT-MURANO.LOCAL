# Sends a file as RAW bytes to a Windows printer via the print spooler
# (winspool.drv). Used by App\Services\LabelPrinterTransport to deliver TSPL
# label commands to a USB label printer without requiring printer sharing.
#
# Usage: powershell -File raw_print.ps1 -Printer "4BARCODE 4B-2054L" -Path C:\tmp\labels.prn
param(
    [Parameter(Mandatory = $true)][string]$Printer,
    [Parameter(Mandatory = $true)][string]$Path
)

$code = @"
using System;
using System.Runtime.InteropServices;

public class RawPrinter
{
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Ansi)]
    public class DOCINFOA
    {
        [MarshalAs(UnmanagedType.LPStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPStr)] public string pDataType;
    }

    [DllImport("winspool.Drv", EntryPoint = "OpenPrinterA", SetLastError = true, CharSet = CharSet.Ansi)]
    public static extern bool OpenPrinter(string szPrinter, out IntPtr hPrinter, IntPtr pd);
    [DllImport("winspool.Drv", EntryPoint = "ClosePrinter")]
    public static extern bool ClosePrinter(IntPtr hPrinter);
    [DllImport("winspool.Drv", EntryPoint = "StartDocPrinterA", SetLastError = true, CharSet = CharSet.Ansi)]
    public static extern bool StartDocPrinter(IntPtr hPrinter, int level, [In] DOCINFOA di);
    [DllImport("winspool.Drv", EntryPoint = "EndDocPrinter")]
    public static extern bool EndDocPrinter(IntPtr hPrinter);
    [DllImport("winspool.Drv", EntryPoint = "StartPagePrinter")]
    public static extern bool StartPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.Drv", EntryPoint = "EndPagePrinter")]
    public static extern bool EndPagePrinter(IntPtr hPrinter);
    [DllImport("winspool.Drv", EntryPoint = "WritePrinter", SetLastError = true)]
    public static extern bool WritePrinter(IntPtr hPrinter, IntPtr pBytes, int dwCount, out int dwWritten);

    public static bool Send(string printerName, byte[] bytes)
    {
        IntPtr handle;
        if (!OpenPrinter(printerName, out handle, IntPtr.Zero)) return false;

        DOCINFOA di = new DOCINFOA();
        di.pDocName = "Stocky Labels";
        di.pDataType = "RAW";

        bool ok = false;
        if (StartDocPrinter(handle, 1, di))
        {
            if (StartPagePrinter(handle))
            {
                IntPtr unmanaged = Marshal.AllocCoTaskMem(bytes.Length);
                Marshal.Copy(bytes, 0, unmanaged, bytes.Length);
                int written;
                ok = WritePrinter(handle, unmanaged, bytes.Length, out written) && written == bytes.Length;
                Marshal.FreeCoTaskMem(unmanaged);
                EndPagePrinter(handle);
            }
            EndDocPrinter(handle);
        }
        ClosePrinter(handle);
        return ok;
    }
}
"@

try {
    Add-Type -TypeDefinition $code -ErrorAction Stop
} catch {
    # Type may already be loaded in a reused runspace; only fail if it's absent.
    if (-not ("RawPrinter" -as [type])) { Write-Error "Add-Type failed: $_"; exit 1 }
}

if (-not (Test-Path -LiteralPath $Path)) { Write-Error "Payload file not found: $Path"; exit 1 }
$bytes = [System.IO.File]::ReadAllBytes($Path)
if ($bytes.Length -eq 0) { Write-Error "Payload file is empty"; exit 1 }

if ([RawPrinter]::Send($Printer, $bytes)) {
    exit 0
}
Write-Error "Could not open or write to printer '$Printer'. Check the exact printer name in Windows settings."
exit 1
