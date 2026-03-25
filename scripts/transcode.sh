#!/bin/bash
# /var/www/html/tajiri/scripts/transcode.sh
# FFmpeg HLS Transcoding Script for TAJIRI Livestreaming (Optimized)
#
# Usage: ./transcode.sh <stream_key>
# Called by RtmpController when a stream starts publishing
#
# Produces: Multi-bitrate HLS streams (360p, 720p, 1080p)
# Target: Ultra-low latency with 2-second segments

set -e

STREAM_KEY=$1
RTMP_URL="rtmp://127.0.0.1/live/${STREAM_KEY}"
HLS_PATH="/var/www/html/tajiri/storage/app/public/hls/${STREAM_KEY}"
LOG_PATH="/var/log/tajiri"

# Ensure directories exist
mkdir -p "${HLS_PATH}"
mkdir -p "${LOG_PATH}"

# Check if already transcoding
PID_FILE="/tmp/transcode_${STREAM_KEY}.pid"
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE")
    if ps -p "$OLD_PID" > /dev/null 2>&1; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Transcoding already running for ${STREAM_KEY} (PID: $OLD_PID)" >> "${LOG_PATH}/transcode.log"
        exit 0
    fi
fi

# Store our PID
echo $$ > "$PID_FILE"
trap "rm -f $PID_FILE" EXIT

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting transcoding for stream: ${STREAM_KEY}" >> "${LOG_PATH}/transcode.log"

# ========== ULTRA-OPTIMIZED HLS TRANSCODING ==========
# - veryfast preset for minimal CPU usage
# - zerolatency tune for reduced encoding delay
# - 2-second segments for low latency
# - GOP = 60 frames (2 seconds at 30fps)

ffmpeg -hide_banner -loglevel warning \
    -i "${RTMP_URL}" \
    -fflags +genpts \
    -filter_complex "[0:v]split=3[v1][v2][v3]; \
        [v1]scale=w=640:h=360:force_original_aspect_ratio=decrease[v1out]; \
        [v2]scale=w=1280:h=720:force_original_aspect_ratio=decrease[v2out]; \
        [v3]scale=w=1920:h=1080:force_original_aspect_ratio=decrease[v3out]" \
    \
    -map "[v1out]" -map 0:a \
    -c:v:0 libx264 \
    -preset veryfast \
    -tune zerolatency \
    -profile:v:0 baseline \
    -level:v:0 3.0 \
    -b:v:0 800k \
    -maxrate:v:0 900k \
    -bufsize:v:0 400k \
    -r:v:0 30 \
    -g:v:0 60 \
    -keyint_min:v:0 60 \
    -sc_threshold:v:0 0 \
    -c:a:0 aac -b:a:0 64k -ar:a:0 48000 -ac:a:0 2 \
    \
    -map "[v2out]" -map 0:a \
    -c:v:1 libx264 \
    -preset veryfast \
    -tune zerolatency \
    -profile:v:1 main \
    -level:v:1 3.1 \
    -b:v:1 2500k \
    -maxrate:v:1 3000k \
    -bufsize:v:1 1250k \
    -r:v:1 30 \
    -g:v:1 60 \
    -keyint_min:v:1 60 \
    -sc_threshold:v:1 0 \
    -c:a:1 aac -b:a:1 128k -ar:a:1 48000 -ac:a:1 2 \
    \
    -map "[v3out]" -map 0:a \
    -c:v:2 libx264 \
    -preset veryfast \
    -tune zerolatency \
    -profile:v:2 high \
    -level:v:2 4.0 \
    -b:v:2 5000k \
    -maxrate:v:2 6000k \
    -bufsize:v:2 2500k \
    -r:v:2 30 \
    -g:v:2 60 \
    -keyint_min:v:2 60 \
    -sc_threshold:v:2 0 \
    -c:a:2 aac -b:a:2 192k -ar:a:2 48000 -ac:a:2 2 \
    \
    -var_stream_map "v:0,a:0,name:360p v:1,a:1,name:720p v:2,a:2,name:1080p" \
    -master_pl_name "master.m3u8" \
    -f hls \
    -hls_time 2 \
    -hls_list_size 5 \
    -hls_flags delete_segments+append_list+independent_segments \
    -hls_delete_threshold 1 \
    -hls_segment_type mpegts \
    -hls_segment_filename "${HLS_PATH}/%v_%03d.ts" \
    "${HLS_PATH}/%v.m3u8" \
    >> "${LOG_PATH}/transcode_${STREAM_KEY}.log" 2>&1 &

FFMPEG_PID=$!
echo $FFMPEG_PID > "$PID_FILE"
echo "[$(date '+%Y-%m-%d %H:%M:%S')] FFmpeg started with PID: $FFMPEG_PID" >> "${LOG_PATH}/transcode.log"

# Wait for FFmpeg to finish
wait $FFMPEG_PID
EXIT_CODE=$?

echo "[$(date '+%Y-%m-%d %H:%M:%S')] FFmpeg exited with code: $EXIT_CODE for stream: ${STREAM_KEY}" >> "${LOG_PATH}/transcode.log"
rm -f "$PID_FILE"

exit $EXIT_CODE
