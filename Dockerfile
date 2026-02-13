FROM php:8.2-fpm-alpine

RUN apk add --no-cache nginx curl

# Configure nginx: daemon off for foreground, and PORT support
RUN echo "daemon off;" >> /etc/nginx/nginx.conf

# Configure PHP-FPM to clear_env=no so env vars are passed through
RUN sed -i 's/;clear_env = no/clear_env = no/' /usr/local/etc/php-fpm.d/www.conf || true
RUN echo "clear_env = no" >> /usr/local/etc/php-fpm.d/www.conf

COPY nginx.conf /etc/nginx/http.d/default.conf
COPY . /app

# Ensure no leftover log/debug files from dev
RUN rm -f /app/*.log /app/*.txt /app/BOOT.txt /app/sign.txt /app/debug_amount.txt 2>/dev/null || true

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s \
  CMD curl -f http://127.0.0.1:8080/nginx-health || exit 1

# Railway passes PORT env — sed it into nginx config at startup
CMD ["sh", "-c", "PORT=${PORT:-8080} && sed -i \"s/listen 8080/listen $PORT/\" /etc/nginx/http.d/default.conf && php-fpm -D && nginx"]
