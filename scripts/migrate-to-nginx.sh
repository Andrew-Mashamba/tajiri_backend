#!/bin/bash
# Apache to Nginx Migration Script for zima-uat.site
# This script performs the migration from Apache to Nginx with RTMP support

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root (sudo)"
    exit 1
fi

echo "=============================================="
echo "Apache to Nginx Migration Script"
echo "Server: zima-uat.site"
echo "=============================================="
echo

# Step 1: Install nginx with RTMP module
log_info "Step 1: Installing nginx with RTMP module..."
apt-get update
apt-get install -y nginx libnginx-mod-rtmp

# Step 2: Ensure PHP-FPM is configured
log_info "Step 2: Configuring PHP-FPM..."
systemctl enable php8.3-fpm
systemctl start php8.3-fpm

# Step 3: Create HLS directories
log_info "Step 3: Creating HLS directories..."
mkdir -p /var/www/html/tajiri/storage/app/public/hls
mkdir -p /var/www/html/tajiri/storage/app/public/hls-ll
chown -R www-data:www-data /var/www/html/tajiri/storage/app/public/hls
chown -R www-data:www-data /var/www/html/tajiri/storage/app/public/hls-ll
chmod -R 755 /var/www/html/tajiri/storage/app/public/hls
chmod -R 755 /var/www/html/tajiri/storage/app/public/hls-ll

# Step 4: Create log directory for transcoding
log_info "Step 4: Creating log directory..."
mkdir -p /var/log/tajiri
chown www-data:www-data /var/log/tajiri

# Step 5: Backup current nginx config
log_info "Step 5: Backing up existing nginx config..."
if [ -f /etc/nginx/nginx.conf ]; then
    cp /etc/nginx/nginx.conf /etc/nginx/nginx.conf.backup.$(date +%Y%m%d%H%M%S)
fi

# Step 6: Update nginx.conf to include rtmp.conf
log_info "Step 6: Updating nginx.conf to include RTMP..."
if ! grep -q "include /etc/nginx/rtmp.conf" /etc/nginx/nginx.conf; then
    # Add include before the last closing brace
    sed -i '/^}$/i include /etc/nginx/rtmp.conf;' /etc/nginx/nginx.conf
fi

# Step 7: Enable the site
log_info "Step 7: Enabling nginx site..."
ln -sf /etc/nginx/sites-available/zima-uat.site /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Step 8: Test nginx configuration
log_info "Step 8: Testing nginx configuration..."
nginx -t

if [ $? -ne 0 ]; then
    log_error "Nginx configuration test failed!"
    log_error "Please fix the configuration and run again."
    exit 1
fi

log_info "Nginx configuration test passed!"

# Step 9: Stop any artisan serve processes for Tajiri
log_info "Step 9: Stopping artisan serve processes..."
pkill -f "artisan serve.*8004" 2>/dev/null || true

# Step 10: Stop Apache
log_info "Step 10: Stopping Apache..."
systemctl stop apache2
systemctl disable apache2

# Step 11: Start nginx
log_info "Step 11: Starting nginx..."
systemctl enable nginx
systemctl start nginx

# Step 12: Verify services
log_info "Step 12: Verifying services..."
echo

echo "Service Status:"
echo "---------------"
systemctl is-active --quiet nginx && echo -e "nginx:      ${GREEN}active${NC}" || echo -e "nginx:      ${RED}inactive${NC}"
systemctl is-active --quiet php8.3-fpm && echo -e "php8.3-fpm: ${GREEN}active${NC}" || echo -e "php8.3-fpm: ${RED}inactive${NC}"
systemctl is-active --quiet apache2 && echo -e "apache2:    ${RED}active (should be stopped)${NC}" || echo -e "apache2:    ${GREEN}stopped${NC}"

echo
echo "=============================================="
echo "Migration Complete!"
echo "=============================================="
echo
echo "Verification commands:"
echo "  curl -I https://zima-uat.site"
echo "  curl https://zima-uat.site:8003/api/streams"
echo
echo "RTMP streaming endpoint:"
echo "  rtmp://zima-uat.site/live/{stream_key}"
echo
echo "HLS playback:"
echo "  https://zima-uat.site/hls/{stream_key}/index.m3u8"
echo
echo "Rollback (if needed):"
echo "  systemctl stop nginx"
echo "  systemctl start apache2"
echo
