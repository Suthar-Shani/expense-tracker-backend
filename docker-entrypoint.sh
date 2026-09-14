#!/bin/bash
set -e

# Render and cloud platforms pass the listening port in the PORT environment variable
PORT="${PORT:-80}"

# Update Apache port configuration dynamically
sed -i "s/Listen [0-9]\+/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]\+>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

echo "Starting Apache web server on port ${PORT}..."

# Execute Apache in foreground
exec apache2-foreground
