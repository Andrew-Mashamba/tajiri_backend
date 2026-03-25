#!/bin/bash
# Rollback Script: Nginx back to Apache
# Use this if the migration causes issues

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m'

log_info() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

if [ "$EUID" -ne 0 ]; then
    log_error "Please run as root (sudo)"
    exit 1
fi

echo "=============================================="
echo "Rollback: Nginx to Apache"
echo "=============================================="
echo

log_info "Stopping nginx..."
systemctl stop nginx

log_info "Starting Apache..."
systemctl start apache2

log_info "Verifying services..."
echo
systemctl is-active --quiet apache2 && echo -e "apache2: ${GREEN}active${NC}" || echo -e "apache2: ${RED}inactive${NC}"
systemctl is-active --quiet nginx && echo -e "nginx:   ${RED}active (should be stopped)${NC}" || echo -e "nginx:   ${GREEN}stopped${NC}"

echo
log_info "Rollback complete!"
echo
echo "Note: You may need to restart artisan serve for port 8004:"
echo "  cd /var/www/html/tajiri && php artisan serve --host=127.0.0.1 --port=8004 &"
echo
