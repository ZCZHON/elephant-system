FROM php:8.2-apache

# ติดตั้ง PostgreSQL Driver
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pgsql pdo_pgsql

# เปิดใช้งาน mod_rewrite ของ Apache
RUN a2enmod rewrite

# คัดลอกไฟล์ทั้งหมดเข้า Container
COPY . /var/www/html/

# สร้างโฟลเดอร์ uploads (ถ้ายังไม่มี) และกำหนดสิทธิ์ Permission ให้ Apache เขียนไฟล์ได้
RUN mkdir -p /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chmod -R 777 /var/www/html/uploads
ENV TZ=Asia/Bangkok
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

EXPOSE 80
