FROM php:8.2-fpm-alpine

RUN apk add --no-cache nginx && \
      echo "daemon off;" >> /etc/nginx/nginx.conf

COPY nginx.conf /etc/nginx/http.d/default.conf
COPY . /app

# Block direct access to log/txt/old files in nginx.conf:
# location ~* \.(log|txt|md)$ { deny all; }
# location /old/ { deny all; }

CMD ["sh", "-c", "php-fpm -D && nginx"]