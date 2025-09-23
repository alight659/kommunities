# Use official PHP with Apache
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install dependencies for SQLite
RUN apt-get update && apt-get install -y libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite \
    && rm -rf /var/lib/apt/lists/*

# Copy source code into container
COPY . /var/www/html

# Give write permission for SQLite database
RUN chmod 664 /var/www/html
RUN chmod -R 777 /var/www/html

# Expose port 80
EXPOSE 80

# Start Apache in foreground
CMD ["apache2-foreground"]