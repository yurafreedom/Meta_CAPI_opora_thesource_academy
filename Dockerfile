FROM php:8.2-cli

WORKDIR /app
COPY . /app

CMD ["sh","-lc","php -S 0.0.0.0:${PORT:-8080} -t ."]