# ============================================================
#  InventorFlow Agent — Windows PowerShell
#  Service NSSM — tourne en boucle toutes les heures
#  Compatible : Windows 10+ / Server 2016+ — PS 5.1 et PS 7+
# ============================================================

# ── CONFIGURATION (injectée par deploy.php) ──────────────────
$IF_SERVER   = "PLACEHOLDER_SERVER"
$IF_API_KEY  = "PLACEHOLDER_KEY"
$IF_DEPT     = ""
$IF_INTERVAL = 300    # secondes entre chaque collecte (5 min)
$IF_REG_EVERY = 12    # enregistrement complet toutes les 12 cycles (60 min)
$IF_LOG      = "C:\ProgramData\InventorFlow\agent.log"
$IF_LAN      = "^192\.168\.|^10\.|^172\.(1[6-9]|2[0-9]|3[01])\."
# ─────────────────────────────────────────────────────────────

# TLS 1.2 forcé (Windows < 10 utilise TLS 1.0 par défaut)
[Net.ServicePointManager]::SecurityProtocol = `
    [Net.SecurityProtocolType]::Tls12 -bor [Net.SecurityProtocolType]::Tls11

Set-StrictMode -Off
$ErrorActionPreference = "SilentlyContinue"

# ── LOGGING ───────────────────────────────────────────────────
function Write-Log {
    param([string]$msg)
    $ts  = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $line = "[$ts] $msg"
    Write-Output $line
    try { Add-Content -Path $IF_LOG -Value $line -Encoding UTF8 } catch {}
}

# ── HTTP POST JSON ────────────────────────────────────────────
function Invoke-ApiPost {
    param([string]$Url, [hashtable]$Body)
    $json = $Body | ConvertTo-Json -Depth 5 -Compress
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($json)
    try {
        $req = [System.Net.HttpWebRequest]::Create($Url)
        $req.Method          = "POST"
        $req.ContentType     = "application/json; charset=utf-8"
        $req.ContentLength   = $bytes.Length
        $req.Timeout         = 15000
        $req.Headers.Add("X-API-Key", $IF_API_KEY)
        $req.Headers.Add("X-Requested-With", "XMLHttpRequest")
        $stream = $req.GetRequestStream()
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Close()
        $resp   = $req.GetResponse()
        $reader = New-Object IO.StreamReader($resp.GetResponseStream())
        $result = $reader.ReadToEnd()
        $resp.Close()
        return @{ Code = [int]$resp.StatusCode; Body = $result }
    } catch [System.Net.WebException] {
        $code = [int]$_.Exception.Response.StatusCode
        $reader = New-Object IO.StreamReader($_.Exception.Response.GetResponseStream())
        return @{ Code = $code; Body = $reader.ReadToEnd() }
    }
}

# ── COLLECTE SYSTÈME ──────────────────────────────────────────
function Get-SystemInfo {
    $cs  = Get-CimInstance Win32_ComputerSystem  -ErrorAction SilentlyContinue
    $os  = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue
    $cpu = Get-CimInstance Win32_Processor       -ErrorAction SilentlyContinue | Select-Object -First 1
    $bio = Get-CimInstance Win32_BIOS            -ErrorAction SilentlyContinue

    # Réseau — première interface physique active
    $adapter = Get-NetAdapter -ErrorAction SilentlyContinue |
        Where-Object { $_.Status -eq 'Up' -and $_.PhysicalMediaType -ne 'Unspecified' } |
        Select-Object -First 1
    $ip = $mac = ""
    if ($adapter) {
        $ip  = (Get-NetIPAddress -InterfaceIndex $adapter.ifIndex -AddressFamily IPv4 -ErrorAction SilentlyContinue).IPAddress
        $mac = $adapter.MacAddress
    }

    # Stockage disque système (C: ou premier disque logique)
    $sysDrive = $env:SystemDrive
    $disk = Get-CimInstance Win32_LogicalDisk -ErrorAction SilentlyContinue |
        Where-Object { $_.DeviceID -eq $sysDrive } | Select-Object -First 1
    if (-not $disk) {
        $disk = Get-CimInstance Win32_LogicalDisk -ErrorAction SilentlyContinue | Select-Object -First 1
    }
    $disk_total = if ($disk) { [math]::Round($disk.Size / 1GB) } else { 0 }

    # RAM totale en Go
    $ram_gb = if ($cs) { [math]::Round($cs.TotalPhysicalMemory / 1GB) } else { 0 }

    return @{
        hostname      = $env:COMPUTERNAME
        os_type       = "WIN"
        os_version    = if ($os)  { "$($os.Caption) $($os.Version)" } else { "Windows" }
        brand         = if ($cs)  { $cs.Manufacturer } else { "" }
        model         = if ($cs)  { $cs.Model } else { "" }
        cpu           = if ($cpu) { $cpu.Name } else { "" }
        ram_gb        = $ram_gb
        storage_gb    = $disk_total
        storage_type  = "SSD"
        serial_number = if ($bio) { $bio.SerialNumber } else { "" }
        ip_address    = if ($ip)  { $ip } else { "" }
        mac_address   = if ($mac) { $mac } else { "" }
        dept_code     = $IF_DEPT
    }
}

# ── MÉTRIQUES TEMPS RÉEL ──────────────────────────────────────
function Get-MonitoringMetrics {
    $os = Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue

    # CPU — deux samples espacés de 500ms pour fiabilité
    $cpu1 = (Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue |
        Measure-Object -Property LoadPercentage -Average).Average
    Start-Sleep -Milliseconds 500
    $cpu2 = (Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue |
        Measure-Object -Property LoadPercentage -Average).Average
    $cpu_pct = [math]::Round(($cpu1 + $cpu2) / 2, 1)

    # RAM
    $ram_total_mb = $ram_used_mb = 0
    if ($os) {
        $ram_total_mb = [math]::Round($os.TotalVisibleMemorySize / 1024)
        $ram_used_mb  = [math]::Round(($os.TotalVisibleMemorySize - $os.FreePhysicalMemory) / 1024)
    }

    # Disque système
    $sysDrive = $env:SystemDrive
    $disk = Get-CimInstance Win32_LogicalDisk -ErrorAction SilentlyContinue |
        Where-Object { $_.DeviceID -eq $sysDrive } | Select-Object -First 1
    $disk_used_gb  = if ($disk) { [math]::Round(($disk.Size - $disk.FreeSpace) / 1GB) } else { 0 }
    $disk_total_gb = if ($disk) { [math]::Round($disk.Size / 1GB) } else { 0 }

    # Uptime en secondes
    $uptime_sec = 0
    if ($os) {
        $uptime_sec = [int](((Get-Date) - $os.LastBootUpTime).TotalSeconds)
    }

    # Processus
    $proc_count = (Get-Process -ErrorAction SilentlyContinue).Count

    # Load average Windows : processor queue length (equivalent)
    $load = 0.0
    try {
        $load = (Get-Counter '\System\Processor Queue Length' -ErrorAction SilentlyContinue).CounterSamples.CookedValue
    } catch {}

    # Température CPU (WMI MSAcpi_ThermalZoneTemperature)
    $temp = $null
    try {
        $zones = Get-CimInstance MSAcpi_ThermalZoneTemperature -Namespace root/wmi -ErrorAction SilentlyContinue
        if ($zones) {
            # Convertir degrés Kelvin × 10 → Celsius
            $temps = $zones | ForEach-Object { [math]::Round(($_.CurrentTemperature - 2732) / 10.0, 1) } |
                Where-Object { $_ -gt 20 -and $_ -lt 120 }
            if ($temps) { $temp = ($temps | Measure-Object -Maximum).Maximum }
        }
    } catch {}

    return @{
        cpu_pct       = $cpu_pct
        ram_used_mb   = $ram_used_mb
        ram_total_mb  = $ram_total_mb
        disk_used_gb  = $disk_used_gb
        disk_total_gb = $disk_total_gb
        load_1m       = $load
        load_5m       = $load
        load_15m      = $load
        uptime_seconds= $uptime_sec
        process_count = $proc_count
        temp_celsius  = $temp
        thermal_state = $null
    }
}

# ── DÉTECTION BRUTE FORCE (EventID 4625) ─────────────────────
function Get-BruteForceEvents {
    $result = @{
        failed_1h  = 0
        failed_24h = 0
        brute_force = @()
    }
    try {
        $cutoff_1h  = (Get-Date).AddHours(-1)
        $cutoff_24h = (Get-Date).AddHours(-24)

        $events = Get-WinEvent -FilterHashtable @{
            LogName   = 'Security'
            Id        = 4625
            StartTime = $cutoff_24h
        } -ErrorAction SilentlyContinue

        if ($events) {
            $external = $events | Where-Object {
                $ip = $_.Properties[19].Value
                $ip -and $ip -notmatch $IF_LAN -and $ip -ne "-" -and $ip -ne "::1" -and $ip -ne "127.0.0.1"
            }
            $result.failed_24h = ($external | Measure-Object).Count
            $result.failed_1h  = ($external | Where-Object { $_.TimeCreated -gt $cutoff_1h } | Measure-Object).Count

            # Agréger par IP
            $byIp = $external | Group-Object { $_.Properties[19].Value } |
                Where-Object { $_.Count -ge 3 } |
                Sort-Object Count -Descending | Select-Object -First 20
            $result.brute_force = @($byIp | ForEach-Object {
                @{ source_ip = $_.Name; attempts = $_.Count }
            })
        }
    } catch {
        Write-Log "WARN brute force: $_"
    }
    return $result
}

# ── ENREGISTREMENT ────────────────────────────────────────────
function Send-Registration {
    $info = Get-SystemInfo
    Write-Log "Collected: hostname=$($info.hostname) os=WIN model=$($info.model) ip=$($info.ip_address)"
    Write-Log "Sending to $IF_SERVER/api/register.php ..."
    $r = Invoke-ApiPost -Url "$IF_SERVER/api/register.php" -Body $info
    if ($r.Code -eq 200) {
        $data = $r.Body | ConvertFrom-Json -ErrorAction SilentlyContinue
        Write-Log "SUCCESS: $($data.message) — hostname: $($data.data.hostname)"
        return $true
    } else {
        Write-Log "ERROR (register): HTTP $($r.Code) — $($r.Body)"
        return $false
    }
}

# ── MONITORING ────────────────────────────────────────────────
function Send-Monitoring {
    Write-Log "Collecting monitoring metrics..."
    $m  = Get-MonitoringMetrics
    $bf = Get-BruteForceEvents

    $temp_str = if ($m.temp_celsius) { "$($m.temp_celsius)°C" } else { "N/A" }
    Write-Log ("Metrics: CPU={0}% RAM={1}/{2}MB disk={3}/{4}GB load={5} temp={6} failed_1h={7}" -f `
        $m.cpu_pct, $m.ram_used_mb, $m.ram_total_mb,
        $m.disk_used_gb, $m.disk_total_gb,
        [math]::Round($m.load_1m, 2), $temp_str, $bf.failed_1h)

    $payload = $m + @{
        hostname        = $env:COMPUTERNAME
        failed_auth_1h  = $bf.failed_1h
        failed_auth_24h = $bf.failed_24h
        brute_force     = $bf.brute_force
    }

    Write-Log "Sending monitoring data to $IF_SERVER/api/monitoring.php ..."
    $r = Invoke-ApiPost -Url "$IF_SERVER/api/monitoring.php" -Body $payload
    if ($r.Code -eq 200) {
        Write-Log "Monitoring data sent successfully"
    } else {
        Write-Log "WARNING (monitoring): HTTP $($r.Code) — $($r.Body)"
    }
}

# ── BOUCLE PRINCIPALE ─────────────────────────────────────────
$cycle = 0
Write-Log "=== InventorFlow Agent démarré (Windows / NSSM) ==="
Write-Log "Serveur : $IF_SERVER"

while ($true) {
    $cycle++
    $doRegister = ($cycle -eq 1) -or ($cycle % $IF_REG_EVERY -eq 0)
    try {
        if ($doRegister) {
            $ok = Send-Registration
        } else { $ok = $true }
        if ($ok) { Send-Monitoring }
    } catch {
        Write-Log "ERREUR inattendue : $_"
    }
    Write-Log "Prochain cycle dans $IF_INTERVAL secondes..."
    Start-Sleep -Seconds $IF_INTERVAL
}
