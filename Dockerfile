FROM php:8.3-cli-bookworm
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends mariadb-server mariadb-client libmariadb-dev curl && docker-php-ext-install pdo_mysql mysqli && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app
RUN chmod +x /app/docker/start.sh && mkdir -p /run/mysqld /var/lib/mysql /app/storage && chown -R mysql:mysql /run/mysqld /var/lib/mysql
ENV PORT=8000 DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=back_in_stock DB_USERNAME=stock_app
VOLUME ["/var/lib/mysql"]
EXPOSE 8000
CMD ["/app/docker/start.sh"]
