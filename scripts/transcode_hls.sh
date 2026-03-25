#!/bin/bash
# /var/www/html/tajiri/scripts/transcode_hls.sh
# HLS Transcoding Script for Adaptive Bitrate Streaming
# Creates 3 quality levels: 360p, 720p, 1080p

set -e

STREAM_KEY="$1"
OUTPUT_DIR="/var/www/html/tajiri/storage/app/public/hls/${STREAM_KEY}"
RTMP_INPUT="rtmp://127.0.0.1/live/${STREAM_KEY}"
LOG_FILE="/var/log/tajiri/transcode_${STREAM_KEY}.log"

# Ensure output directory exists
mkdir -p "$OUTPUT_DIR"
mkdir -p /var/log/tajiri

# Log start
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting HLS transcoding for stream: ${STREAM_KEY}" >> "$LOG_FILE"

# ========== ULTRA-OPTIMIZED HLS TRANSCODING ==========
# Creates master.m3u8 with 3 quality variants

ffmpeg -hide_banner -loglevel warning \
  -i "$RTMP_INPUT" \
  -fflags +genpts \
  -analyzeduration 1000000 \
  -probesize 1000000 \
  \
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
  -g:v:0 30 \
  -keyint_min:v:0 30 \
  -sc_threshold:v:0 0 \
  -c:a:0 aac -b:a:0 64k -ar:a:0 48000 \
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
  -g:v:1 30 \
  -keyint_min:v:1 30 \
  -sc_threshold:v:1 0 \
  -c:a:1 aac -b:a:1 128k -ar:a:1 48000 \
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
  -g:v:2 30 \
  -keyint_min:v:2 30 \
  -sc_threshold:v:2 0 \
  -c:a:2 aac -b:a:2 192k -ar:a:2 48000 \
  \
  -f hls \
  -hls_time 2 \
  -hls_list_size 5 \
  -hls_flags delete_segments+append_list+independent_segments \
  -hls_delete_threshold 1 \
  -hls_segment_type mpegts \
  -master_pl_name master.m3u8 \
  -var_stream_map "v:0,a:0,name:360p v:1,a:1,name:720p v:2,a:2,name:1080p" \
  "${OUTPUT_DIR}/stream_%v.m3u8" \
  >> "$LOG_FILE" 2>&1

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Transcoding ended for stream: ${STREAM_KEY}" >> "$LOG_FILE"
