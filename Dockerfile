FROM php:8.2-apache

# ติดตั้ง PostgreSQL Driver สำหรับ PHP
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pgsql pdo_pgsql

# เปิดใช้งาน mod_rewrite ของ Apache
RUN a2enmod rewrite

# คัดลอกโค้ดทั้งหมดเข้า Container
COPY . /var/www/html/

EXPOSE 80