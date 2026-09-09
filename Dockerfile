FROM php:8.4-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN a2enmod rewrite

# Recorded video clips can be several MB — raise PHP's default upload
# limits (2M/8M) so recordings_save.php can actually receive them.
RUN { \
      echo 'upload_max_filesize = 200M'; \
      echo 'post_max_size = 200M'; \
      echo 'max_execution_time = 120'; \
      echo 'memory_limit = 256M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini
