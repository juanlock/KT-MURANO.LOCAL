# IT Admin (Murano) - network scan, generated for one network. Read-only on the network: it pings its IPs, reads this PC's ARP table and
# asks DNS for names; it changes nothing on this PC or on any device. It sends the result only to the address below.
# Run it on a Windows PC of that office:  powershell -ExecutionPolicy Bypass -File <this file>

$UploadUrl  = '%%URL%%'
$Token      = '%%TOKEN%%'
$NetName    = '%%NETWORK_NAME%%'
$FirstHost  = '%%FIRST%%'
$LastHost   = '%%LAST%%'
$Network    = '%%NETWORK%%'
$Cidr       = %%CIDR%%
New-Item -ItemType Directory -Force -Path 'C:\temp' | Out-Null
$OutFile    = 'C:\temp\{0}-%%FILE_TAG%%-{1}.scan' -f (Get-Date -Format 'yyyyMMdd-HHmmss'), $env:COMPUTERNAME

$ErrorActionPreference = 'Continue'

function ConvertTo-Long([string]$ip) {
    $b = ([System.Net.IPAddress]::Parse($ip)).GetAddressBytes()
    return ([uint32]$b[0] * 16777216) + ([uint32]$b[1] * 65536) + ([uint32]$b[2] * 256) + [uint32]$b[3]
}
function ConvertTo-Ip([uint32]$n) {
    return '{0}.{1}.{2}.{3}' -f (($n -shr 24) -band 255), (($n -shr 16) -band 255), (($n -shr 8) -band 255), ($n -band 255)
}
function Wait-Tasks($tasks, [int]$ms) {
    if ($tasks.Count -gt 0) {
        try { [void][System.Threading.Tasks.Task]::WaitAll([System.Threading.Tasks.Task[]]$tasks, $ms) } catch { }
    }
}
function Stop-Here {
    Write-Host ''
    Read-Host 'Press Enter to close'
    exit
}

Write-Host ''
Write-Host "IT Admin - scanning $NetName ($Network/$Cidr)" -ForegroundColor Cyan

$from = ConvertTo-Long $FirstHost
$to   = ConvertTo-Long $LastHost
$all  = @(for ($n = $from; $n -le $to; $n++) { ConvertTo-Ip ([uint32]$n) })

# The card of this PC on that network: only then can MAC addresses be read.
$localIp = $null; $ifIndex = $null; $localMac = $null
try {
    foreach ($a in (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop)) {
        $l = ConvertTo-Long $a.IPAddress
        if ($l -ge $from -and $l -le $to) { $localIp = $a.IPAddress; $ifIndex = $a.InterfaceIndex; break }
    }
    if ($ifIndex) { $localMac = (Get-NetAdapter -InterfaceIndex $ifIndex -ErrorAction Stop).MacAddress }
} catch { Write-Host "Could not read this PC's network cards: $_" -ForegroundColor Yellow }
if ($localIp) {
    Write-Host "This PC is on that network: $localIp - MAC addresses will be read." -ForegroundColor Green
} else {
    Write-Host 'This PC has NO card on that network: IPs and names only, no MAC addresses.' -ForegroundColor Yellow
}

# 1. Ping every IP (twice for the silent ones); this also fills the ARP table of the card on that network.
Write-Host "Pinging $($all.Count) IPs..."
$alive = @{}
foreach ($pass in 1..2) {
    $todo = @($all | Where-Object { -not $alive.ContainsKey($_) })
    for ($i = 0; $i -lt $todo.Count; $i += 64) {
        $batch = @($todo[$i..([Math]::Min($i + 63, $todo.Count - 1))])
        $jobs = @()
        foreach ($ip in $batch) {
            $p = New-Object System.Net.NetworkInformation.Ping
            $jobs += [pscustomobject]@{ Ip = $ip; Task = $p.SendPingAsync($ip, 900) }
        }
        Wait-Tasks @($jobs | ForEach-Object { $_.Task }) 2500
        foreach ($j in $jobs) {
            if ($j.Task.Status -eq 'RanToCompletion' -and $j.Task.Result.Status -eq 'Success') { $alive[$j.Ip] = 'ping' }
        }
    }
}
Write-Host "  $($alive.Count) answered the ping."

# 2. ARP table of that card: devices that block ping still answer ARP.
$macs = @{}
if ($ifIndex) {
    Start-Sleep -Seconds 2
    try {
        foreach ($nb in (Get-NetNeighbor -InterfaceIndex $ifIndex -AddressFamily IPv4 -ErrorAction Stop)) {
            if ($nb.State -in @('Reachable', 'Stale', 'Delay', 'Probe') -and $nb.LinkLayerAddress -and $nb.LinkLayerAddress -ne '00-00-00-00-00-00') {
                $l = ConvertTo-Long $nb.IPAddress
                if ($l -ge $from -and $l -le $to) {
                    $macs[$nb.IPAddress] = $nb.LinkLayerAddress
                    if (-not $alive.ContainsKey($nb.IPAddress)) { $alive[$nb.IPAddress] = 'arp' }
                }
            }
        }
    } catch { Write-Host "Could not read the ARP table: $_" -ForegroundColor Yellow }
    if ($localIp) {
        $alive[$localIp] = 'self'
        if ($localMac) { $macs[$localIp] = $localMac }
    }
    Write-Host "  $($macs.Count) MAC addresses read."
}

# 3. Not on that network: common ports find what blocks ping.
if (-not $ifIndex) {
    $silent = @($all | Where-Object { -not $alive.ContainsKey($_) })
    if ($silent.Count -le 1024) {
        Write-Host "Trying common ports on $($silent.Count) silent IPs..."
        for ($i = 0; $i -lt $silent.Count; $i += 64) {
            $batch = @($silent[$i..([Math]::Min($i + 63, $silent.Count - 1))])
            $jobs = @()
            foreach ($ip in $batch) {
                foreach ($port in 80, 443, 445) {
                    $c = New-Object System.Net.Sockets.TcpClient
                    $jobs += [pscustomobject]@{ Ip = $ip; Client = $c; Task = $c.ConnectAsync($ip, $port) }
                }
            }
            Wait-Tasks @($jobs | ForEach-Object { $_.Task }) 1200
            foreach ($j in $jobs) {
                if ($j.Task.Status -eq 'RanToCompletion' -and $j.Client.Connected) { $alive[$j.Ip] = 'port' }
                try { $j.Client.Close() } catch { }
            }
        }
    }
}

# 4. Names (reverse DNS).
Write-Host "Asking the names of $($alive.Count) devices..."
$names = @{}
$jobs = @()
foreach ($ip in $alive.Keys) { $jobs += [pscustomobject]@{ Ip = $ip; Task = [System.Net.Dns]::GetHostEntryAsync($ip) } }
Wait-Tasks @($jobs | ForEach-Object { $_.Task }) 8000
foreach ($j in $jobs) {
    if ($j.Task.Status -eq 'RanToCompletion' -and $j.Task.Result.HostName -and $j.Task.Result.HostName -ne $j.Ip) { $names[$j.Ip] = $j.Task.Result.HostName }
}

# 5. Upload (a copy stays in a file, in case it has to be uploaded by hand).
$results = @($alive.Keys | ForEach-Object { @{ ip = $_; mac = $macs[$_]; hostname = $names[$_]; method = $alive[$_] } })
$payload = @{
    computer = $env:COMPUTERNAME; localIp = $localIp; connected = [bool]$ifIndex
    scannedAt = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'); hosts = $all.Count; results = $results
} | ConvertTo-Json -Depth 4
[System.IO.File]::WriteAllText($OutFile, $payload)

Write-Host "Found $($results.Count) devices. Uploading..."
try {
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($payload)
    $r = Invoke-RestMethod -Uri $UploadUrl -Method Post -Body $bytes -ContentType 'application/json; charset=utf-8' -Headers @{ 'X-Scan-Token' = $Token } -TimeoutSec 120
    Write-Host "Uploaded: $($r.message)" -ForegroundColor Green
    Write-Host 'Done. Review it whenever you want on the web page.' -ForegroundColor Green
} catch {
    Write-Host "The upload failed: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "Upload this file by hand on the web page: $OutFile" -ForegroundColor Yellow
}
Stop-Here
