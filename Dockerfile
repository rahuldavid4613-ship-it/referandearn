# Use official PHP Apache image
FROM php:8.1-apache

# Install required extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache rewrite module
RUN a2enmod rewrite

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install dependencies
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
    libzip-dev \
    unzip && \
    rm -rf /var/lib/apt/lists/*

# Create necessary files with proper permissions
RUN touch users.json error.log && \
    chmod 666 users.json error.log && \
    chown -R www-data:www-data /var/www/html

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
