FROM php:8.2-cli

# Dependências do sistema
RUN apt-get update && apt-get install -y \
    ffmpeg \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install \
        mysqli \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY . /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080"]
