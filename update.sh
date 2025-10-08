#!/bin/bash

# ==============================================================================
# Pinty Monitor Client Script v2.0
# ==============================================================================
# 请在这里配置您的信息
# ------------------------------------------------------------------------------
# API端点，用于接收状态报告
API_ENDPOINT="https://your-domain.com/report.php"
# 服务器的唯一ID (与管理员后台设置的ID匹配)
SERVER_ID="your-server-id"
# 每个服务器独立的密钥 (从管理员后台复制)
SECRET="your-secret-key"
# ==============================================================================

# 错误报告日志文件
LOG_FILE="/var/log/pinty_monitor_client.log"

echo "Starting Pinty monitoring script for $SERVER_ID..."
echo "Reporting to $API_ENDPOINT. Errors will be logged to $LOG_FILE"

# --- 函数定义 ---

# 获取网络使用情况的函数
get_network_usage() {
    INTERFACE=$(ip route | grep '^default' | awk '{print $5}' | head -n 1)
    if [ -z "$INTERFACE" ]; then
        if ip link show eth0 > /dev/null 2>&1; then INTERFACE="eth0";
        elif ip link show venet0 > /dev/null 2>&1; then INTERFACE="venet0"; # For OpenVZ
        elif ip link show ens3 > /dev/null 2>&1; then INTERFACE="ens3"; # Common alternative
        else echo "Error: Could not determine a valid network interface." >&2; return 1; fi
    fi
    
    # Check if interface stats exist
    if [ ! -f "/sys/class/net/$INTERFACE/statistics/rx_bytes" ]; then
        echo "Error: Statistics not found for interface $INTERFACE." >&2
        return 1
    fi

    RX_BYTES_START=$(cat /sys/class/net/$INTERFACE/statistics/rx_bytes)
    TX_BYTES_START=$(cat /sys/class/net/$INTERFACE/statistics/tx_bytes)
    sleep 1
    RX_BYTES_END=$(cat /sys/class/net/$INTERFACE/statistics/rx_bytes)
    TX_BYTES_END=$(cat /sys/class/net/$INTERFACE/statistics/tx_bytes)

    RX_SPEED=$((RX_BYTES_END - RX_BYTES_START))
    TX_SPEED=$((TX_BYTES_END - TX_BYTES_START))
    TOTAL_UP=$(cat /sys/class/net/$INTERFACE/statistics/tx_bytes)
    TOTAL_DOWN=$(cat /sys/class/net/$INTERFACE/statistics/rx_bytes)

    echo "\"net_up_speed\": ${TX_SPEED:-0}, \"net_down_speed\": ${RX_SPEED:-0}, \"total_up\": ${TOTAL_UP:-0}, \"total_down\": ${TOTAL_DOWN:-0}"
}

# --- 首次运行时获取静态信息 ---
# 使用一个标志文件来判断是否是首次运行
FIRST_RUN_FLAG="/tmp/pinty_first_run_$$"
if [ ! -f "$FIRST_RUN_FLAG" ]; then
    CPU_MODEL=$(grep 'model name' /proc/cpuinfo | head -n 1 | cut -d ':' -f 2 | sed 's/^[ \t]*//')
    CPU_CORES=$(nproc)
    MEM_TOTAL_BYTES=$(free -b | awk 'NR==2{print $2}')
    DISK_TOTAL_BYTES=$(df -B1 / | awk 'NR==2{print $2}')
    OS_INFO=$( (lsb_release -ds 2>/dev/null || cat /etc/*-release 2>/dev/null | head -n1 || uname -om) | tr -d '"' )
    ARCH=$(uname -m)

    STATIC_INFO_JSON=$(cat <<EOF
,
"static_info": {
    "cpu_model": "$CPU_MODEL",
    "cpu_cores": ${CPU_CORES:-0},
    "mem_total_bytes": ${MEM_TOTAL_BYTES:-0},
    "disk_total_bytes": ${DISK_TOTAL_BYTES:-0},
    "system": "$OS_INFO",
    "arch": "$ARCH"
}
EOF
)
    touch "$FIRST_RUN_FLAG"
    # 添加一个at job，在脚本退出后一段时间删除标志文件，以备重启
    echo "rm -f $FIRST_RUN_FLAG" | at now + 5 minutes > /dev/null 2>&1
else
    STATIC_INFO_JSON=""
fi
# --- 静态信息获取结束 ---

# 主循环
while true; do
    CPU_USAGE=$(awk '{u=$2+$4; t=$2+$4+$5; if (NR==1){u1=u; t1=t;} else print ($2+$4-u1) * 100 / (t-t1); }' <(grep 'cpu ' /proc/stat) <(sleep 1;grep 'cpu ' /proc/stat) | awk '{printf "%.2f", $0}')
    MEM_INFO=$(free | awk 'NR==2{printf "\"mem_usage_percent\": %.2f", $3/$2*100}')
    DISK_USAGE=$(df / | awk 'NR==2{print $5}' | sed 's/%//')
    UPTIME=$(uptime -p | sed 's/up //')
    LOAD_AVG=$(awk '{print $1}' /proc/loadavg)
    PROCESSES=$(ps -e --no-headers | wc -l)
    CONNECTIONS=$( (ss -tn | grep -v 'State' | wc -l) || (netstat -nt | grep -v 'State' | wc -l) )
    
    NET_USAGE_JSON=$(get_network_usage)
    if [ $? -ne 0 ]; then
        NET_USAGE_JSON='"net_up_speed": 0, "net_down_speed": 0, "total_up": 0, "total_down": 0'
    fi

    JSON_PAYLOAD=$(cat <<EOF
{
    "server_id": "$SERVER_ID",
    "secret": "$SECRET",
    "cpu_usage": ${CPU_USAGE:-0},
    ${MEM_INFO:-"\"mem_usage_percent\": 0"},
    "disk_usage_percent": ${DISK_USAGE:-0},
    "uptime": "$UPTIME",
    "load_avg": ${LOAD_AVG:-0},
    "processes": ${PROCESSES:-0},
    "connections": ${CONNECTIONS:-0},
    ${NET_USAGE_JSON}
    ${STATIC_INFO_JSON}
}
EOF
)
    
    # 首次报告后，不再发送静态信息以节省带宽
    STATIC_INFO_JSON=""

    HTTP_RESPONSE=$(curl --write-out "HTTPSTATUS:%{http_code}" -s -X POST -H "Content-Type: application/json" -d "$JSON_PAYLOAD" "$API_ENDPOINT")
    
    HTTP_STATUS=$(echo "$HTTP_RESPONSE" | tr -d '\n' | sed -e 's/.*HTTPSTATUS://')
    HTTP_BODY=$(echo "$HTTP_RESPONSE" | sed -e 's/HTTPSTATUS\:.*//g')
    
    if [ "$HTTP_STATUS" -ne 200 ]; then
        ERROR_MSG="[$(date)] - Failed to report data. Status: $HTTP_STATUS, Response: $HTTP_BODY"
        echo "$ERROR_MSG" >&2
        echo "$ERROR_MSG" >> "$LOG_FILE"
    fi

    sleep 8 # sleep 8s + 1s from network = 9s interval
done
