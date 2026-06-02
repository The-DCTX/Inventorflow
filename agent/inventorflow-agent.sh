#!/usr/bin/env bash
# ============================================================
#  InventorFlow Agent — Auto-registration script
#  Compatible: macOS 12+, Debian/Ubuntu/RHEL/Alpine Linux
#
#  Usage:
#    chmod +x inventorflow-agent.sh
#    ./inventorflow-agent.sh
#
#  Cron (every hour):
#    0 * * * * /usr/local/bin/inventorflow-agent.sh >> /var/log/inventorflow-agent.log 2>&1
#
#  Cron (at boot + every 6h):
#    @reboot /usr/local/bin/inventorflow-agent.sh
#    0 */6 * * * /usr/local/bin/inventorflow-agent.sh
# ============================================================

set -euo pipefail

# ── CONFIGURATION ────────────────────────────────────────────
IF_SERVER="http://localhost:8083"
IF_API_KEY="REPLACE_WITH_YOUR_API_KEY"
IF_DEPT_CODE=""          # Optional: department code (IT, RH, DIR…)
IF_TIMEOUT=10            # HTTP timeout in seconds
IF_INTERVAL=300          # Monitoring : toutes les 5 min
IF_REG_EVERY=12          # Enregistrement complet : toutes les 12 cycles (60 min)
IF_LAN_WHITELIST="192\.168\.\."  # IPs LAN ignorées (regex) — pas d'alerte pour le réseau local
IF_LOG="/var/log/inventorflow-agent.log"
IF_AGENT_VERSION="1.0.6"  # version embarquée (auto-update)
IF_SELF_UPDATE=true       # false pour désactiver la mise à jour automatique de l'agent
# ─────────────────────────────────────────────────────────────

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"; }

# ── AUTO-UPDATE (l'agent se met à jour seul depuis le serveur) ──
# Compare deux versions sémantiques : succès (0) si $1 > $2.
_version_gt() {
    [[ "$1" == "$2" ]] && return 1
    local IFS=. a b i x y
    a=($1); b=($2)
    for ((i=0; i<${#a[@]} || i<${#b[@]}; i++)); do
        x=${a[i]:-0}; y=${b[i]:-0}
        ((10#$x > 10#$y)) && return 0
        ((10#$x < 10#$y)) && return 1
    done
    return 1
}

self_update() {
    [[ "${IF_SELF_UPDATE:-true}" == "true" ]] || return 0
    [[ -n "${IF_SELFUPDATED:-}" ]] && return 0                          # garde anti-boucle
    [[ "$IF_API_KEY" == "REPLACE_WITH_YOUR_API_KEY" ]] && return 0      # agent non configuré
    local remote
    remote=$(curl -fsSL --max-time "${IF_TIMEOUT:-10}" "${IF_SERVER}/api/agent-version.php" 2>/dev/null \
             | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
    [[ -z "$remote" ]] && return 0
    _version_gt "$remote" "$IF_AGENT_VERSION" || return 0
    log "Auto-update : $IF_AGENT_VERSION -> $remote"
    local self tmp newver
    self="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
    [[ -w "$self" ]] || { log "Auto-update ignoré (pas de droit d'écriture sur $self)"; return 0; }
    tmp="$(mktemp)" || return 0
    if curl -fsSL --max-time 30 "${IF_SERVER}/deploy.php?key=${IF_API_KEY}&raw=1" -o "$tmp" 2>/dev/null \
       && bash -n "$tmp" 2>/dev/null \
       && grep -q '^IF_AGENT_VERSION=' "$tmp"; then
        newver=$(grep -m1 '^IF_AGENT_VERSION=' "$tmp" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)
        if [[ "$newver" == "$remote" ]]; then
            cat "$tmp" > "$self" && chmod +x "$self"
            rm -f "$tmp"
            log "Auto-update OK ($newver) — redémarrage de l'agent"
            export IF_SELFUPDATED=1
            exec "$self" "$@"
        fi
    fi
    rm -f "$tmp"
    log "Auto-update ignoré (téléchargement/validation échoués)"
    return 0
}

# ── CYCLE COUNTER ────────────────────────────────────────────
# Enregistrement complet toutes les IF_REG_EVERY cycles
# Monitoring à chaque cycle
_COUNTER_FILE="/tmp/.inventorflow_cycle"
_CYCLE=$(cat "$_COUNTER_FILE" 2>/dev/null | tr -cd '0-9' || echo 0)
_CYCLE=$(( _CYCLE + 1 ))
printf '%d' "$_CYCLE" > "$_COUNTER_FILE" 2>/dev/null || true
_DO_REGISTER=false
[[ $_CYCLE -eq 1 || $(( _CYCLE % ${IF_REG_EVERY:-12} )) -eq 0 ]] && _DO_REGISTER=true

# Detect OS
if [[ "$(uname)" == "Darwin" ]]; then
    OS_FAMILY="mac"
elif [[ "$(uname)" == "Linux" ]]; then
    OS_FAMILY="linux"
else
    log "ERROR: Unsupported OS: $(uname)"
    exit 1
fi

# Auto-update avant tout traitement (peut relancer l'agent via exec)
self_update "$@"

# ── COLLECT SYSTEM INFO ──────────────────────────────────────

collect_mac() {
    # LocalHostName = nom réseau Bonjour (sans espaces, compatible hostname)
    HOSTNAME=$(scutil --get LocalHostName 2>/dev/null || scutil --get HostName 2>/dev/null || hostname -s 2>/dev/null || echo "unknown")
    # Sanitize: espaces → tirets, caractères spéciaux supprimés
    HOSTNAME=$(printf '%s' "$HOSTNAME" | tr ' ' '-' | tr -cd '[:alnum:]-')
    OS_TYPE="MAC"
    OS_VERSION=$(sw_vers -productVersion 2>/dev/null || echo "")
    OS_NAME="macOS ${OS_VERSION}"

    # Hardware info via system_profiler
    SP=$(system_profiler SPHardwareDataType 2>/dev/null)
    SERIAL=$(echo "$SP" | awk -F': ' '/Serial Number/ {gsub(/ /,"",$2); print $2}')
    MODEL=$(echo "$SP"  | awk -F': ' '/Model Name/    {sub(/^ /,"",$2); print $2}')
    CPU=$(echo "$SP"    | awk -F': ' '/Chip|Processor Name/ {sub(/^ /,"",$2); print $2; exit}')
    RAM_BYTES=$(echo "$SP" | awk -F': ' '/Memory:/ {
        val=$2; gsub(/^ /,"",val);
        if (val ~ /GB/) { n=val+0; print n*1073741824 }
        else if (val ~ /TB/) { n=val+0; print n*1099511627776 }
        else print val
    }')
    RAM_GB=$(( (${RAM_BYTES:-0} + 536870912) / 1073741824 ))
    BRAND="Apple"

    # Storage
    STORAGE_INFO=$(diskutil info / 2>/dev/null)
    STORAGE_BYTES=$(echo "$STORAGE_INFO" | awk -F'[()]' '/Total Size/ {match($2,/[0-9]+/); print substr($2,RSTART,RLENGTH)}')
    STORAGE_GB=$(( (${STORAGE_BYTES:-0} + 536870912) / 1073741824 ))
    STORAGE_TYPE="SSD"

    # Network — primary interface
    IP=$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo "")
    MAC_ADDR=$(ifconfig en0 2>/dev/null | awk '/ether/ {print $2}' | head -1)

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}

collect_linux() {
    HOSTNAME=$(hostname -s 2>/dev/null || cat /etc/hostname | tr -d '[:space:]')
    OS_TYPE="LIN"

    # OS version — guard against minimal /etc/os-release without PRETTY_NAME (set -u safe)
    if command -v lsb_release &>/dev/null; then
        OS_NAME=$(lsb_release -sd 2>/dev/null | tr -d '"')
    elif [[ -f /etc/os-release ]]; then
        OS_NAME=$(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME:-${NAME:-$(uname -r)}}")
    else
        OS_NAME=$(uname -r)
    fi
    OS_VERSION="$OS_NAME"

    # CPU
    CPU=$(grep -m1 'model name' /proc/cpuinfo 2>/dev/null | cut -d':' -f2 | xargs)
    [[ -z "$CPU" ]] && CPU=$(lscpu 2>/dev/null | awk -F': +' '/Model name/ {print $2; exit}') || true

    # RAM
    RAM_KB=$(grep MemTotal /proc/meminfo 2>/dev/null | awk '{print $2}')
    RAM_GB=$(( (${RAM_KB:-0} + 524288) / 1048576 ))

    # Storage (root partition) — use -k for BusyBox/Alpine compatibility (df -BG is GNU-only)
    STORAGE_GB=$(df -k / 2>/dev/null | awk 'NR==2 {printf "%d", ($2+524288)/1048576}')
    STORAGE_TYPE="SSD"
    # Try to detect if rotational — handle sda1, nvme0n1p1, mmcblk0p1
    ROOT_DEV=$(df / 2>/dev/null | awk 'NR==2 {print $1}' | sed 's|/dev/||; s|p[0-9]*$||; s|[0-9]*$||')
    if [[ -f "/sys/block/${ROOT_DEV}/queue/rotational" ]]; then
        ROT=$(cat "/sys/block/${ROOT_DEV}/queue/rotational" 2>/dev/null)
        [[ "$ROT" == "1" ]] && STORAGE_TYPE="HDD" || true
    fi
    # Check for NVMe / eMMC
    [[ "$ROOT_DEV" == nvme* ]] && STORAGE_TYPE="NVMe" || true
    [[ "$ROOT_DEV" == mmcblk* ]] && STORAGE_TYPE="eMMC" || true

    # Hardware info
    BRAND=""
    MODEL=""
    SERIAL=""
    if command -v dmidecode &>/dev/null && [[ $EUID -eq 0 ]]; then
        BRAND=$(dmidecode -s system-manufacturer 2>/dev/null | head -1 | xargs)
        MODEL=$(dmidecode -s system-product-name 2>/dev/null | head -1 | xargs)
        SERIAL=$(dmidecode -s system-serial-number 2>/dev/null | head -1 | xargs)
        [[ "$SERIAL" == "To Be Filled By O.E.M." ]] && SERIAL="" || true
    elif [[ -f /sys/class/dmi/id/product_name ]]; then
        MODEL=$(cat /sys/class/dmi/id/product_name 2>/dev/null | xargs)
        BRAND=$(cat /sys/class/dmi/id/sys_vendor 2>/dev/null | xargs)
        SERIAL=$(cat /sys/class/dmi/id/product_serial 2>/dev/null | xargs)
    fi

    # Network — default route interface
    IFACE=$(ip route get 1.1.1.1 2>/dev/null | awk '/dev/ {for(i=1;i<=NF;i++) if($i=="dev") print $(i+1)}' | head -1)
    IP=$(ip addr show "$IFACE" 2>/dev/null | awk '/inet / {split($2,a,"/"); print a[1]}' | head -1)
    MAC_ADDR=$(ip link show "$IFACE" 2>/dev/null | awk '/link\/ether/ {print $2}' | head -1)

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}


ensure_osx_cpu_temp() {
    if command -v osx-cpu-temp &>/dev/null; then
        log "osx-cpu-temp déjà installé"
        return 0
    fi

    local BIN="/usr/local/bin/osx-cpu-temp"
    local SRC="/tmp/_osx_smc_src"
    local CC
    CC=$(command -v clang || command -v cc 2>/dev/null || true)

    if [[ -z "$CC" ]]; then
        log "WARN: clang absent — installez Xcode CLT: xcode-select --install"
        return 1
    fi

    log "osx-cpu-temp absent — compilation depuis GitHub (clang: $CC)..."
    rm -rf "$SRC" && mkdir -p "$SRC"

    log "  → Téléchargement smc.c..."
    if ! curl -fsSL --max-time 15 \
        "https://raw.githubusercontent.com/lavoiesl/osx-cpu-temp/master/smc.c" \
        -o "$SRC/smc.c" 2>/dev/null; then
        log "WARN: Téléchargement smc.c échoué (réseau?)"
        rm -rf "$SRC"; return 1
    fi
    curl -fsSL --max-time 15 \
        "https://raw.githubusercontent.com/lavoiesl/osx-cpu-temp/master/smc.h" \
        -o "$SRC/smc.h" 2>/dev/null || true

    log "  → Compilation en cours..."
    local compile_err
    compile_err=$("$CC" -o "$BIN" "$SRC/smc.c" \
        -framework IOKit -framework CoreFoundation 2>&1)

    if [[ -f "$BIN" ]]; then
        chmod +x "$BIN"
        rm -rf "$SRC"
        log "  ✓ osx-cpu-temp installé dans $BIN"
        return 0
    else
        log "  ✗ Compilation échouée: $compile_err"
        rm -rf "$SRC"; return 1
    fi

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}


collect_temp_mac() {
    MON_TEMP=""
    MON_THERMAL_STATE=""
    local arch
    arch=$(uname -m 2>/dev/null || echo "unknown")

    if [[ "$arch" == "arm64" ]]; then
        # ── Apple Silicon (M1/M2/M3/M4) ─────────────────────────────
        # powermetrics -s thermal gives thermal pressure level, not a number
        local pm_out
        pm_out=$(/usr/bin/powermetrics -s thermal -n 1 2>/dev/null || true)
        if [[ -n "$pm_out" ]]; then
            MON_THERMAL_STATE=$(printf '%s' "$pm_out"                 | grep -iE 'pressure level|thermal level|CPU thermal'                 | grep -oiE 'nominal|high|critical'                 | head -1 | tr '[:upper:]' '[:lower:]' || true)
        fi
    else
        # ── Intel (x86_64) — détecter le modèle pour log
        local mac_model
        mac_model=$(sysctl -n hw.model 2>/dev/null || echo "Intel Mac")
        log "Collecte température — $mac_model (x86_64)"
        # Mac Pro 5,1 / 4,1 : powermetrics SMC ne fonctionne pas, nécessite osx-cpu-temp
        # iMac / MacBook Intel récents : powermetrics fonctionne
        # powermetrics SMC ne fonctionne pas sur Mac Pro pre-2013 (bug Apple)
        # Stratégie : osx-cpu-temp → smctemp → powermetrics smc → ioreg
        local t=""

        # 1. osx-cpu-temp — auto-compilé depuis GitHub si absent (pas besoin de Homebrew)
        ensure_osx_cpu_temp 2>/dev/null || true
        if [[ -x "/usr/local/bin/osx-cpu-temp" ]] || command -v osx-cpu-temp &>/dev/null; then
            _osx_cmd=$(command -v osx-cpu-temp 2>/dev/null || echo "/usr/local/bin/osx-cpu-temp")
            t=$("$_osx_cmd" -T 2>/dev/null | grep -oE '[0-9]+\.?[0-9]*' | head -1 || true)

        # 2. smctemp (brew install smctemp) — alternative
        elif command -v smctemp &>/dev/null; then
            t=$(smctemp -c 2>/dev/null | grep -oE '[0-9]+\.?[0-9]*' | head -1 || true)

        # 3. powermetrics SMC — fonctionne sur iMac/MacBook Intel récents (pas Mac Pro 5,1)
        else
            local pm_out
            pm_out=$(/usr/bin/powermetrics --samplers smc -n1 -i500 2>/dev/null || true)
            if [[ -n "$pm_out" ]]; then
                t=$(printf '%s' "$pm_out" \
                    | grep -i "CPU die temperature" \
                    | grep -oE '[0-9]+\.?[0-9]*' \
                    | head -1 || true)
            fi

            # 4. ioreg — lecture native SMC (Mac Pro 4,1 / 5,1 compatible)
            if [[ -z "$t" ]]; then
                t=$(ioreg -r -c IOPlatformExpertDevice -d3 2>/dev/null \
                    | grep -iE '"TC[0-9][DC]"|"CPU die"' \
                    | grep -oE '[0-9]+\.?[0-9]*' \
                    | awk '$1+0 > 20 && $1+0 < 120 {print $1+0}' \
                    | sort -n | tail -1 || true)
            fi
        fi

        # Valider la plage (20-110°C)
        if [[ -n "$t" ]]; then
            # awk: conversion locale-indépendante (evite bug printf avec locale fr_FR)
            local ti; ti=$(awk "BEGIN{printf \"%d\", $t+0}" 2>/dev/null || echo 0)
            [[ $ti -ge 20 && $ti -le 110 ]] && MON_TEMP="$t" || log "WARN: temp $t hors plage 20-110°C"
        fi
    fi

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}


collect_temp_linux() {
    MON_TEMP=""
    local t=""

    # Method 1: /sys/class/thermal — most portable (works on RPi, most SoCs, x86)
    t=$(for f in /sys/class/thermal/thermal_zone*/temp; do
            [[ -f "$f" ]] && cat "$f" 2>/dev/null || true
        done 2>/dev/null \
        | grep -E '^[0-9]+$' \
        | sort -n | tail -1 || true)

    if [[ -n "$t" && $t -gt 1000 ]]; then
        MON_TEMP=$(awk "BEGIN{printf \"%.1f\", $t/1000}" 2>/dev/null || true)
        [[ -n "$MON_TEMP" ]] && return 0
    fi

    # Method 2: hwmon (higher precision on x86 with lm-sensors kernel modules)
    t=$(for f in /sys/class/hwmon/hwmon*/temp*_input; do
            [[ -f "$f" ]] && cat "$f" 2>/dev/null || true
        done 2>/dev/null \
        | grep -E '^[0-9]+$' \
        | sort -n | tail -1 || true)

    if [[ -n "$t" && $t -gt 1000 ]]; then
        MON_TEMP=$(awk "BEGIN{printf \"%.1f\", $t/1000}" 2>/dev/null || true)
        [[ -n "$MON_TEMP" ]] && return 0
    fi

    # Method 3: sensors (lm-sensors userspace tool — optional)
    if command -v sensors &>/dev/null; then
        t=$(sensors 2>/dev/null \
            | grep -oE '\+[0-9]+\.[0-9]+' \
            | tr -d '+' \
            | awk '$1+0 > 20 && $1+0 < 120 {print $1+0}' \
            | sort -n | tail -1 || true)
        [[ -n "$t" ]] && MON_TEMP="$t" || true
    fi

    return 0  # Never fail: VM/unsupported hardware → MON_TEMP stays empty

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}

# Run collection
if [[ "$OS_FAMILY" == "mac" ]]; then
    collect_mac
else
    collect_linux
fi

# ── JSON helpers (GLOBAUX : utilisés par l'enregistrement ET le monitoring) ──
# Pure-bash JSON string escaping — no Python dependency
json_str() {
    local val="${1:-}"
    val="${val//\\/\\\\}"     # 1. backslashes
    val="${val//\"/\\\"}"     # 2. double-quotes
    val="$(printf '%s' "$val" | tr -d '\000-\031' 2>/dev/null || printf '%s' "$val")"  # 3. control chars
    printf '"%s"' "$val"
}
# Alias for backwards compat
json_escape() { json_str "$@"; }

if [[ "$_DO_REGISTER" == "true" ]]; then
log "Collected: hostname=${HOSTNAME} os=${OS_TYPE} model=${MODEL} ip=${IP:-none} serial=${SERIAL:-none}"

# ── BUILD JSON PAYLOAD ───────────────────────────────────────

PAYLOAD=$(cat <<EOF
{
  "hostname":      $(json_escape "$HOSTNAME"),
  "os_type":       $(json_escape "$OS_TYPE"),
  "os_version":    $(json_escape "${OS_VERSION:-}"),
  "brand":         $(json_escape "${BRAND:-}"),
  "model":         $(json_escape "${MODEL:-}"),
  "cpu":           $(json_escape "${CPU:-}"),
  "ram_gb":        ${RAM_GB:-0},
  "storage_gb":    ${STORAGE_GB:-0},
  "storage_type":  $(json_escape "${STORAGE_TYPE:-SSD}"),
  "serial_number": $(json_escape "${SERIAL:-}"),
  "ip_address":    $(json_escape "${IP:-}"),
  "mac_address":   $(json_escape "${MAC_ADDR:-}"),
  "dept_code":     $(json_escape "${IF_DEPT_CODE:-}")
}
EOF
)

# ── SEND TO API ──────────────────────────────────────────────

log "Sending to ${IF_SERVER}/api/register.php ..."

RESPONSE=$(curl -sL -w "\n%{http_code}" \
    --max-time "$IF_TIMEOUT" \
    -X POST \
    -H "Content-Type: application/json" \
    -H "X-API-Key: ${IF_API_KEY}" \
    -d "$PAYLOAD" \
    "${IF_SERVER}/api/register.php" 2>/dev/null)

# Portable last-line / all-but-last extraction (avoids GNU-only `head -n -1`)
HTTP_CODE=$(printf '%s' "$RESPONSE" | tail -1 | tr -d '\r')
BODY=$(printf '%s\n' "$RESPONSE" | awk 'NR>1{print prev} {prev=$0}')

if [[ "$HTTP_CODE" == "200" ]]; then
    ACTION=$(echo "$BODY" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("data",{}).get("action","?"))' 2>/dev/null || echo "ok")
    ASSET_HOSTNAME=$(echo "$BODY" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("data",{}).get("hostname","?"))' 2>/dev/null || echo "?")
    log "SUCCESS: ${ACTION} — hostname: ${ASSET_HOSTNAME}"
else
    ERROR=$(echo "$BODY" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("error","unknown"))' 2>/dev/null || echo "$BODY")
    log "ERROR (register): HTTP ${HTTP_CODE} — ${ERROR}"
    exit 1
fi

fi  # _DO_REGISTER

# ── MONITORING COLLECTION ────────────────────────────────────

collect_metrics_mac() {
    # CPU: two-sample top to get a real usage figure (not idle snapshot)
    MON_CPU=$(top -l2 -n0 2>/dev/null | grep "CPU usage" | tail -1 | awk '{print $3}' | tr -d '%')
    MON_CPU=${MON_CPU:-0}

    # RAM: pages active × 4096 bytes → MB; total from sysctl
    VM_STAT_OUT=$(vm_stat 2>/dev/null)
    PAGES_ACTIVE=$(echo "$VM_STAT_OUT" | awk '/Pages active/ {gsub(/\./,"",$3); print $3}')
    MON_RAM_USED_MB=$(( (${PAGES_ACTIVE:-0} * 4096) / 1048576 ))
    MEM_BYTES=$(sysctl -n hw.memsize 2>/dev/null || echo 0)
    MON_RAM_TOTAL_MB=$(( ${MEM_BYTES:-0} / 1048576 ))

    # Disk: use diskutil info / for accurate APFS container stats (df shows volume only)
    _dutil=$(diskutil info / 2>/dev/null)
    _disk_total_bytes=$(echo "$_dutil" | awk -F'[()]' '/Container Total Space/{match($2,/[0-9]+/); print substr($2,RSTART,RLENGTH)}')
    _disk_free_bytes=$(echo  "$_dutil" | awk -F'[()]' '/Container Free Space/{match($2,/[0-9]+/); print substr($2,RSTART,RLENGTH)}')
    if [[ -n "$_disk_total_bytes" && "$_disk_total_bytes" -gt 0 ]] 2>/dev/null; then
        MON_DISK_TOTAL_GB=$(( (_disk_total_bytes + 536870912) / 1073741824 ))
        MON_DISK_USED_GB=$(( (_disk_total_bytes - ${_disk_free_bytes:-0} + 536870912) / 1073741824 ))
    else
        # fallback: df -k (less accurate on APFS)
        read -r DISK_USED_K DISK_TOTAL_K <<< "$(df -k / 2>/dev/null | awk 'NR==2{print $3,$2}')" || true
        MON_DISK_USED_GB=$(( (${DISK_USED_K:-0} + 524288) / 1048576 ))
        MON_DISK_TOTAL_GB=$(( (${DISK_TOTAL_K:-0} + 524288) / 1048576 ))
    fi

    # Load averages
    read -r MON_LOAD_1M MON_LOAD_5M MON_LOAD_15M <<< \
        "$(sysctl -n vm.loadavg 2>/dev/null | awk '{print $2,$3,$4}' | tr ',' '.')" || true
    MON_LOAD_1M=${MON_LOAD_1M:-0}
    MON_LOAD_5M=${MON_LOAD_5M:-0}
    MON_LOAD_15M=${MON_LOAD_15M:-0}

    # Uptime: now - boot time
    BOOT_SEC=$(sysctl -n kern.boottime 2>/dev/null | awk -F'sec = ' '{print $2}' | awk -F',' '{print int($1)}')
    NOW_SEC=$(date +%s)
    MON_UPTIME=$(( ${NOW_SEC:-0} - ${BOOT_SEC:-0} ))
    [[ $MON_UPTIME -lt 0 ]] && MON_UPTIME=0 || true

    # Process count (subtract 1 for the header line)
    MON_PROCS=$(( $(ps aux 2>/dev/null | wc -l) - 1 ))
    [[ $MON_PROCS -lt 0 ]] && MON_PROCS=0 || true

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}

collect_metrics_linux() {
    # CPU: /proc/stat two samples — works on all Linux (Alpine, DietPi, RHEL, etc.)
    # Replaces `top -bn2` which adds a hard-coded 3s delay
    _cpu1=$(grep '^cpu ' /proc/stat 2>/dev/null || echo "cpu 0 0 0 1 0 0 0 0 0 0")
    sleep 1
    _cpu2=$(grep '^cpu ' /proc/stat 2>/dev/null || echo "cpu 0 0 0 1 0 0 0 0 0 0")
    MON_CPU=$(awk -v s1="$_cpu1" -v s2="$_cpu2" 'BEGIN{
        n=split(s1,a," "); m=split(s2,b," ")
        idle1=a[5]+0; idle2=b[5]+0
        tot1=0; tot2=0
        for(i=2;i<=n&&i<=9;i++){tot1+=a[i]+0}
        for(i=2;i<=m&&i<=9;i++){tot2+=b[i]+0}
        dtot=tot2-tot1
        if(dtot>0) printf "%d", int(100*(1-(idle2-idle1)/dtot))
        else print 0
    }')
    MON_CPU=${MON_CPU:-0}

    # RAM from /proc/meminfo via free -m
    read -r MON_RAM_USED_MB MON_RAM_TOTAL_MB <<< \
        "$(free -m 2>/dev/null | awk '/Mem:/{print $3,$2}')" || true
    MON_RAM_USED_MB=${MON_RAM_USED_MB:-0}
    MON_RAM_TOTAL_MB=${MON_RAM_TOTAL_MB:-0}

    # Disk — use -k for BusyBox/Alpine compatibility (df -BG is GNU-only)
    read -r MON_DISK_USED_GB MON_DISK_TOTAL_GB <<< \
        "$(df -k / 2>/dev/null | awk 'NR==2{printf "%d %d", ($3+524288)/1048576, ($2+524288)/1048576}')" || true
    MON_DISK_USED_GB=${MON_DISK_USED_GB:-0}
    MON_DISK_TOTAL_GB=${MON_DISK_TOTAL_GB:-0}

    # Load averages
    read -r MON_LOAD_1M MON_LOAD_5M MON_LOAD_15M <<< \
        "$(cat /proc/loadavg 2>/dev/null | awk '{print $1,$2,$3}')" || true
    MON_LOAD_1M=${MON_LOAD_1M:-0}
    MON_LOAD_5M=${MON_LOAD_5M:-0}
    MON_LOAD_15M=${MON_LOAD_15M:-0}

    # Uptime in integer seconds
    MON_UPTIME=$(cat /proc/uptime 2>/dev/null | awk '{print int($1)}')
    MON_UPTIME=${MON_UPTIME:-0}

    # Process count (subtract header)
    MON_PROCS=$(( $(ps aux 2>/dev/null | wc -l) - 1 ))
    [[ $MON_PROCS -lt 0 ]] && MON_PROCS=0 || true

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}

collect_brute_force_mac() {
    MON_FAILED_1H=0
    MON_FAILED_24H=0
    BRUTE_JSON="[]"

    # Récupère UNIQUEMENT les échecs SSH (sshd) — pas sudo, pas GUI, pas local
    local RAW_LINES=""
    if command -v perl &>/dev/null; then
        RAW_LINES=$(perl -e 'alarm 20; exec @ARGV' \
            log show \
            --predicate 'process == "sshd" AND (eventMessage CONTAINS "Failed password" OR eventMessage CONTAINS "Invalid user")' \
            --last 24h 2>/dev/null | head -5000 || true)
    else
        RAW_LINES=$(log show \
            --predicate 'process == "sshd" AND (eventMessage CONTAINS "Failed password" OR eventMessage CONTAINS "Invalid user")' \
            --last 24h 2>/dev/null | head -5000 || true)
    fi

    # Extraire uniquement les lignes avec une IP source externe
    # Format: "...Failed password for X from 1.2.3.4 port..."
    # Filtrer IPs LAN, localhost, vide
    local EXT_LINES=""
    EXT_LINES=$(printf '%s' "$RAW_LINES" \
        | grep -oE 'from [0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' \
        | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' \
        | grep -vE "${IF_LAN_WHITELIST:-^$}" \
        | grep -vE '^127\.|^::1$' \
        || true)

    # Compter les IPs externes — grep -c . retourne 0 sur vide (pas comme wc -l)
    MON_FAILED_24H=$(printf '%s' "$EXT_LINES" | grep -c . 2>/dev/null || true)

    if [[ $MON_FAILED_24H -gt 0 ]]; then
        # Compter la dernière heure
        local ONE_HOUR_AGO
        ONE_HOUR_AGO=$(date -v-1H '+%Y-%m-%d %H:%M:%S' 2>/dev/null \
            || perl -e 'use POSIX strftime; print strftime("%Y-%m-%d %H:%M:%S", localtime(time()-3600))' 2>/dev/null \
            || echo "")

        if [[ -n "$ONE_HOUR_AGO" ]]; then
            # Filtrer les lignes RAW par timestamp puis recompter les IPs externes
            local RECENT_IPS
            RECENT_IPS=$(printf '%s' "$RAW_LINES" \
                | awk -v cutoff="$ONE_HOUR_AGO" '$0 >= cutoff' \
                | grep -oE 'from [0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' \
                | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' \
                | grep -vE "${IF_LAN_WHITELIST:-^$}" \
                | grep -vE '^127\.' || true)
            MON_FAILED_1H=$(printf '%s' "$RECENT_IPS" | grep -c . 2>/dev/null || true)
        fi

        # Top attaquants — agréger par IP
        BRUTE_JSON=$(printf '%s' "$EXT_LINES" \
            | sort | uniq -c | sort -rn | head -20 \
            | awk '{printf "{\"source_ip\":\"%s\",\"attempts\":%d},", $2, $1}' \
            | sed 's/,$//' \
            | awk '{print "["$0"]"}' || echo "[]")
    fi

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}

collect_brute_force_linux() {
    MON_BF_JSON="[]"
    MON_FAILED_1H=0
    MON_FAILED_24H=0
    BRUTE_JSON="[]"

    # Find auth log
    AUTH_LINES=""
    if [[ -r /var/log/auth.log ]]; then
        AUTH_LINES=$(grep -E 'sshd.*(Failed password|Invalid user)' /var/log/auth.log 2>/dev/null | head -10000 || true)
    elif [[ -r /var/log/secure ]]; then
        AUTH_LINES=$(grep -E 'sshd.*(Failed password|Invalid user)' /var/log/secure 2>/dev/null | head -10000 || true)
    else
        # journalctl can flood RAM under heavy attack; bound it with `timeout` when available,
        # otherwise fall back to a background-kill pattern. Cap line count regardless.
        if command -v timeout &>/dev/null; then
            AUTH_LINES=$(timeout 10 journalctl -u sshd --since "24 hours ago" \
                --no-pager --output=short 2>/dev/null \
                | grep -E 'Failed password|Invalid user' \
                | head -10000 || true)
        else
            # No `timeout` (rare on Linux but possible on stripped containers):
            # run in background, kill after 10s, ignore exit status.
            _tmp_journal=$(mktemp 2>/dev/null || echo "/tmp/if_journal.$$")
            ( journalctl -u sshd --since "24 hours ago" --no-pager --output=short 2>/dev/null \
                | grep -E 'Failed password|Invalid user' \
                | head -10000 > "$_tmp_journal" 2>/dev/null ) &
            _journal_pid=$!
            ( sleep 10 && kill "$_journal_pid" 2>/dev/null ) &
            _killer_pid=$!
            wait "$_journal_pid" 2>/dev/null || true
            kill "$_killer_pid" 2>/dev/null || true
            wait "$_killer_pid" 2>/dev/null || true
            AUTH_LINES=$(cat "$_tmp_journal" 2>/dev/null || true)
            rm -f "$_tmp_journal" 2>/dev/null || true
        fi
    fi

    # Supplement with lastb (failed logins from btmp) — bound with timeout to avoid
    # reading gigabyte-sized btmp files on long-running attacked servers.
    if command -v timeout &>/dev/null; then
        LASTB_LINES=$(timeout 5 lastb 2>/dev/null | head -50 || true)
    else
        LASTB_LINES=$(timeout 5 lastb 2>/dev/null | head -50 || true)
    fi

    ALL_LINES="${AUTH_LINES}"$'\n'"${LASTB_LINES}"

    if [[ -n "$ALL_LINES" ]]; then
        # `grep -c .` returns 1 on empty input — `|| echo 0` makes it set -e safe.
        MON_FAILED_24H=$(echo "$AUTH_LINES" | grep -c . 2>/dev/null || true)

        # Portable "1 hour ago" epoch: perl → GNU date → python3 → 0
        # BusyBox `date` lacks `-d 'N units ago'`; perl is universally available on
        # Debian/Ubuntu/RHEL/Alpine systems with sshd installed.
        ONE_HOUR_AGO_EPOCH=$(perl -e 'print time()-3600' 2>/dev/null \
            || date -d '1 hour ago' +%s 2>/dev/null \
            || python3 -c 'import time; print(int(time.time())-3600)' 2>/dev/null \
            || echo 0)
        if [[ ${ONE_HOUR_AGO_EPOCH:-0} -gt 0 ]]; then
            # Count lines with timestamps in the last hour
            # syslog format: "Mon DD HH:MM:SS" — use current year
            YEAR=$(date +%Y)
            MON_FAILED_1H=$(echo "$AUTH_LINES" | python3 -c "
import sys, time, re
now = time.time()
cutoff = now - 3600
year = '$YEAR'
count = 0
for line in sys.stdin:
    m = re.match(r'(\w{3}\s+\d+\s+\d+:\d+:\d+)', line)
    if m:
        try:
            t = time.mktime(time.strptime(year + ' ' + m.group(1), '%Y %b %d %H:%M:%S'))
            if t >= cutoff:
                count += 1
        except Exception:
            pass
print(count)
" 2>/dev/null || echo 0)
        fi

        # Extract IPs and count per IP from auth lines
        BRUTE_JSON=$(echo "$AUTH_LINES" | \
            grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' | \
            sort | uniq -c | sort -rn | head -20 | \
            python3 -c '
import sys, json
entries = []
for line in sys.stdin:
    parts = line.strip().split()
    if len(parts) == 2:
        entries.append({"source_ip": parts[1], "attempts": int(parts[0])})
print(json.dumps(entries))
' 2>/dev/null || echo "[]")
    fi
    # Sync to MON_BF_JSON for payload
    MON_BF_JSON="${BRUTE_JSON:-[]}"

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}


# ── POLLING DES COMMANDES ─────────────────────────────────────
poll_commands() {
    local resp cmd cmd_id
    resp=$(curl -sL --max-time "${IF_TIMEOUT}"         -H "X-API-Key: ${IF_API_KEY}"         "${IF_SERVER}/api/commands.php?hostname=${HOSTNAME}" 2>/dev/null || true)

    cmd=$(printf '%s' "$resp" | python3 -c         'import json,sys; d=json.load(sys.stdin); print((d.get("data") or {}).get("command",""))'         2>/dev/null || true)
    cmd_id=$(printf '%s' "$resp" | python3 -c         'import json,sys; d=json.load(sys.stdin); print((d.get("data") or {}).get("id",""))'         2>/dev/null || true)

    [[ -z "$cmd" || -z "$cmd_id" ]] && return 0

    log "Command received: ${cmd} (id=${cmd_id})"

    case "$cmd" in
        force_report)
            # Re-run monitoring immediately
            if [[ "$OS_FAMILY" == "mac" ]]; then
                collect_metrics_mac    || true
                collect_temp_mac       || true
                collect_brute_force_mac || true
            else
                collect_metrics_linux    || true
                collect_temp_linux       || true
                collect_brute_force_linux || true
            fi
            send_monitoring 2>/dev/null || send_monitoring
            log "force_report executed"
            ;;
        ping)
            log "ping OK — agent alive"
            ;;
        update_agent)
            log "update_agent: re-deploying..."
            curl -fsSL "${IF_SERVER}/deploy.php?key=${IF_API_KEY}" | bash &
            ;;
    esac

    # Sync vers MON_BF_JSON (comme collect_brute_force_linux)
    MON_BF_JSON="${BRUTE_JSON:-[]}"
}

# ── COLLECT METRICS AND SECURITY DATA ────────────────────────

log "Collecting monitoring metrics..."

# Default values — overridden by collect functions
MON_CPU=0; MON_RAM_USED_MB=0; MON_RAM_TOTAL_MB=0
MON_DISK_USED_GB=0; MON_DISK_TOTAL_GB=0
MON_LOAD_1M=0; MON_LOAD_5M=0; MON_LOAD_15M=0
MON_UPTIME=0; MON_PROCS=0
MON_FAILED_1H=0; MON_FAILED_24H=0; MON_BF_JSON="[]"
MON_TEMP=""
MON_THERMAL_STATE=""

if [[ "$OS_FAMILY" == "mac" ]]; then
    collect_metrics_mac    || log "WARNING: metrics collection partial"
    collect_temp_mac       || true
    collect_brute_force_mac || log "WARNING: brute force collection skipped"
else
    collect_metrics_linux    || log "WARNING: metrics collection partial"
    collect_temp_linux       || true
    collect_brute_force_linux || log "WARNING: brute force collection skipped"
fi

log "Metrics: CPU=${MON_CPU}% RAM=${MON_RAM_USED_MB}/${MON_RAM_TOTAL_MB}MB disk=${MON_DISK_USED_GB}/${MON_DISK_TOTAL_GB}GB load=${MON_LOAD_1M} temp=${MON_TEMP:-N/A}°C thermal=${MON_THERMAL_STATE:-N/A} failed_1h=${MON_FAILED_1H}"

# ── BUILD MONITORING PAYLOAD ─────────────────────────────────

# ── SANITIZE VARS FOR JSON ───────────────────────────────────
_int() { printf '%s' "${1:-0}" | grep -oE '^-?[0-9]+' | head -1 || echo 0; }
_num() { printf '%s' "${1:-0}" | grep -oE '^-?[0-9]+\.?[0-9]*' | head -1 || echo 0; }
MON_CPU=$(       _int "$MON_CPU")
MON_RAM_USED_MB=$(_int "$MON_RAM_USED_MB")
MON_RAM_TOTAL_MB=$(_int "$MON_RAM_TOTAL_MB")
MON_DISK_USED_GB=$(_int "$MON_DISK_USED_GB")
MON_DISK_TOTAL_GB=$(_int "$MON_DISK_TOTAL_GB")
MON_LOAD_1M=$(   _num "$MON_LOAD_1M")
MON_LOAD_5M=$(   _num "$MON_LOAD_5M")
MON_LOAD_15M=$(  _num "$MON_LOAD_15M")
MON_UPTIME=$(    _int "$MON_UPTIME")
MON_PROCS=$(     _int "$MON_PROCS")
MON_FAILED_1H=$( _int "$MON_FAILED_1H")
MON_FAILED_24H=$(_int "$MON_FAILED_24H")
# Temperature: validate range or set to empty (→ null in JSON)
[[ -n "$MON_TEMP" ]] && { _t=$(_num "$MON_TEMP"); [[ $(awk "BEGIN{printf \"%d\", ${_t:-0}+0}" 2>/dev/null || echo 0) -ge 20 ]] && MON_TEMP="$_t" || MON_TEMP=""; } || true
# Thermal state: keep only known values, quote for JSON
[[ "${MON_THERMAL_STATE:-}" =~ ^(nominal|high|critical)$ ]] \
    && MON_THERMAL_STATE_JSON='"'$MON_THERMAL_STATE'"' \
    || MON_THERMAL_STATE_JSON="null"
# BF: keep only first line, ensure valid JSON array
MON_BF_JSON=$(printf '%s' "${MON_BF_JSON:-[]}" | head -1 | grep -E '^\[.*\]$' || echo "[]")

MON_PAYLOAD=$(cat <<EOF
{
  "hostname":        $(json_escape "$HOSTNAME"),
  "cpu_pct":         ${MON_CPU},
  "ram_used_mb":     ${MON_RAM_USED_MB},
  "ram_total_mb":    ${MON_RAM_TOTAL_MB},
  "disk_used_gb":    ${MON_DISK_USED_GB},
  "disk_total_gb":   ${MON_DISK_TOTAL_GB},
  "load_1m":         ${MON_LOAD_1M},
  "load_5m":         ${MON_LOAD_5M},
  "load_15m":        ${MON_LOAD_15M},
  "uptime_seconds":  ${MON_UPTIME},
  "process_count":   ${MON_PROCS},
  "temp_celsius":    ${MON_TEMP:-null},
  "thermal_state":   ${MON_THERMAL_STATE_JSON:-null},
  "failed_auth_1h":  ${MON_FAILED_1H},
  "failed_auth_24h": ${MON_FAILED_24H},
  "brute_force":     ${MON_BF_JSON:-[]}
}
EOF
)

# ── SEND MONITORING DATA ─────────────────────────────────────

log "Sending monitoring data to ${IF_SERVER}/api/monitoring.php ..."

MON_RESPONSE=$(curl -sL -w "\n%{http_code}" \
    --max-time "$IF_TIMEOUT" \
    -X POST \
    -H "Content-Type: application/json" \
    -H "X-API-Key: ${IF_API_KEY}" \
    -d "$MON_PAYLOAD" \
    "${IF_SERVER}/api/monitoring.php" 2>/dev/null)

# Portable last-line / all-but-last extraction (avoids GNU-only `head -n -1`)
MON_HTTP_CODE=$(printf '%s' "$MON_RESPONSE" | tail -1 | tr -d '\r')
MON_BODY=$(printf '%s\n' "$MON_RESPONSE" | awk 'NR>1{print prev} {prev=$0}')

if [[ "$MON_HTTP_CODE" == "200" ]]; then
    log "Monitoring data sent successfully"
else
    MON_ERROR=$(echo "$MON_BODY" | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d.get("error","unknown"))' 2>/dev/null || echo "$MON_BODY")
    log "WARNING (monitoring): HTTP ${MON_HTTP_CODE} — ${MON_ERROR}"
    # Non-fatal: register succeeded, monitoring failure is a warning only
fi

exit 0
