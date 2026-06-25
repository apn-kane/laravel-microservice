# Phase 1: Infrastructure & Docker Setup

> Set up the entire Docker environment including PHP-FPM, Nginx, PostgreSQL, Kafka, and Zookeeper.

---

## Prerequisites

- Docker Desktop installed and running
- Docker Compose v2+
- A terminal (bash/zsh/PowerShell)

---

## Step 1.1 — Create the PHP Dockerfile

**File:** `docker/php/Dockerfile`

This Dockerfile builds a PHP 8.3-FPM image with all required extensions for Laravel + Kafka.

```dockerfile
FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    librdkafka-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Set permissions
RUN chown -R www-data:www-data /var/www
```

**What this does:**
- Starts from official PHP 8.3-FPM image
- Installs system libraries needed for PostgreSQL, GD, and Kafka
- Installs PHP extensions: `pdo_pgsql`, `pgsql`, `mbstring`, `exif`, `pcntl`, `bcmath`, `gd`
- Installs `rdkafka` PHP extension via PECL (required for `mateusjunges/laravel-kafka`)
- Copies Composer binary from official Composer image
- Sets `/var/www` as the working directory

---

## Step 1.2 — Create Nginx Configuration Files

Each service gets its own Nginx config that proxies to its PHP-FPM container.

### **File:** `docker/nginx/user-service.conf`

```nginx
server {
    listen 80;
    index index.php index.html;
    error_log  /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;
    root /var/www/public;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass user-service:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
        gzip_static on;
    }
}
```

### **File:** `docker/nginx/product-service.conf`

```nginx
server {
    listen 80;
    index index.php index.html;
    error_log  /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;
    root /var/www/public;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass product-service:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
        gzip_static on;
    }
}
```

### **File:** `docker/nginx/order-service.conf`

```nginx
server {
    listen 80;
    index index.php index.html;
    error_log  /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;
    root /var/www/public;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass order-service:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
        gzip_static on;
    }
}
```

### **File:** `docker/nginx/api-gateway.conf`

```nginx
server {
    listen 80;
    index index.php index.html;
    error_log  /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;
    root /var/www/public;

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass api-gateway:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
        gzip_static on;
    }
}
```

**Key point:** The `fastcgi_pass` directive uses the Docker service name + port 9000 (PHP-FPM default).

---

## Step 1.3 — Create `docker-compose.yml`

**File:** `docker-compose.yml` (project root)

```yaml
version: '3.8'

services:
  # ─── PHP-FPM Services ───────────────────────────────────────
  user-service:
    build:
      context: ./docker/php
      dockerfile: Dockerfile
    container_name: user-service
    volumes:
      - ./user-service:/var/www
    networks:
      - microservices
    depends_on:
      - user-db
      - kafka

  product-service:
    build:
      context: ./docker/php
      dockerfile: Dockerfile
    container_name: product-service
    volumes:
      - ./product-service:/var/www
    networks:
      - microservices
    depends_on:
      - product-db
      - kafka

  order-service:
    build:
      context: ./docker/php
      dockerfile: Dockerfile
    container_name: order-service
    volumes:
      - ./order-service:/var/www
    networks:
      - microservices
    depends_on:
      - order-db
      - kafka

  api-gateway:
    build:
      context: ./docker/php
      dockerfile: Dockerfile
    container_name: api-gateway
    volumes:
      - ./api-gateway:/var/www
    networks:
      - microservices

  # ─── Nginx Services ─────────────────────────────────────────
  user-nginx:
    image: nginx:alpine
    container_name: user-nginx
    ports:
      - "8001:80"
    volumes:
      - ./user-service:/var/www
      - ./docker/nginx/user-service.conf:/etc/nginx/conf.d/default.conf
    networks:
      - microservices
    depends_on:
      - user-service

  product-nginx:
    image: nginx:alpine
    container_name: product-nginx
    ports:
      - "8002:80"
    volumes:
      - ./product-service:/var/www
      - ./docker/nginx/product-service.conf:/etc/nginx/conf.d/default.conf
    networks:
      - microservices
    depends_on:
      - product-service

  order-nginx:
    image: nginx:alpine
    container_name: order-nginx
    ports:
      - "8003:80"
    volumes:
      - ./order-service:/var/www
      - ./docker/nginx/order-service.conf:/etc/nginx/conf.d/default.conf
    networks:
      - microservices
    depends_on:
      - order-service

  gateway-nginx:
    image: nginx:alpine
    container_name: gateway-nginx
    ports:
      - "8000:80"
    volumes:
      - ./api-gateway:/var/www
      - ./docker/nginx/api-gateway.conf:/etc/nginx/conf.d/default.conf
    networks:
      - microservices
    depends_on:
      - api-gateway

  # ─── Databases ──────────────────────────────────────────────
  user-db:
    image: postgres:16-alpine
    container_name: user-db
    environment:
      POSTGRES_DB: user_service
      POSTGRES_USER: user_user
      POSTGRES_PASSWORD: user_password
    ports:
      - "5433:5432"
    volumes:
      - user-db-data:/var/lib/postgresql/data
    networks:
      - microservices

  product-db:
    image: postgres:16-alpine
    container_name: product-db
    environment:
      POSTGRES_DB: product_service
      POSTGRES_USER: product_user
      POSTGRES_PASSWORD: product_password
    ports:
      - "5434:5432"
    volumes:
      - product-db-data:/var/lib/postgresql/data
    networks:
      - microservices

  order-db:
    image: postgres:16-alpine
    container_name: order-db
    environment:
      POSTGRES_DB: order_service
      POSTGRES_USER: order_user
      POSTGRES_PASSWORD: order_password
    ports:
      - "5435:5432"
    volumes:
      - order-db-data:/var/lib/postgresql/data
    networks:
      - microservices

  # ─── Kafka + Zookeeper ──────────────────────────────────────
  zookeeper:
    image: confluentinc/cp-zookeeper:7.5.0
    container_name: zookeeper
    environment:
      ZOOKEEPER_CLIENT_PORT: 2181
      ZOOKEEPER_TICK_TIME: 2000
    ports:
      - "2181:2181"
    networks:
      - microservices

  kafka:
    image: confluentinc/cp-kafka:7.5.0
    container_name: kafka
    depends_on:
      - zookeeper
    ports:
      - "9093:9093"
    environment:
      KAFKA_BROKER_ID: 1
      KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
      KAFKA_ADVERTISED_LISTENERS: INTERNAL://kafka:9092,EXTERNAL://localhost:9093
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: INTERNAL:PLAINTEXT,EXTERNAL:PLAINTEXT
      KAFKA_LISTENERS: INTERNAL://0.0.0.0:9092,EXTERNAL://0.0.0.0:9093
      KAFKA_INTER_BROKER_LISTENER_NAME: INTERNAL
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
      KAFKA_AUTO_CREATE_TOPICS_ENABLE: "true"
    networks:
      - microservices

  # ─── Kafka UI (Optional) ────────────────────────────────────
  kafka-ui:
    image: provectuslabs/kafka-ui:latest
    container_name: kafka-ui
    ports:
      - "8080:8080"
    environment:
      KAFKA_CLUSTERS_0_NAME: local
      KAFKA_CLUSTERS_0_BOOTSTRAPSERVERS: kafka:9092
      KAFKA_CLUSTERS_0_ZOOKEEPER: zookeeper:2181
    networks:
      - microservices
    depends_on:
      - kafka

# ─── Volumes ────────────────────────────────────────────────
volumes:
  user-db-data:
  product-db-data:
  order-db-data:

# ─── Networks ───────────────────────────────────────────────
networks:
  microservices:
    driver: bridge
```

**Port mapping summary:**

| Service | Host Port | Container Port |
|---------|-----------|----------------|
| API Gateway (Nginx) | 8000 | 80 |
| User Service (Nginx) | 8001 | 80 |
| Product Service (Nginx) | 8002 | 80 |
| Order Service (Nginx) | 8003 | 80 |
| User DB (PostgreSQL) | 5433 | 5432 |
| Product DB (PostgreSQL) | 5434 | 5432 |
| Order DB (PostgreSQL) | 5435 | 5432 |
| Kafka (external) | 9093 | 9093 |
| Zookeeper | 2181 | 2181 |
| Kafka UI | 8080 | 8080 |

---

## Step 1.4 — Create Root `.env.example`

**File:** `.env.example` (project root)

```env
# ─── User Service DB ──────────────────────────────
USER_DB_HOST=user-db
USER_DB_PORT=5432
USER_DB_DATABASE=user_service
USER_DB_USERNAME=user_user
USER_DB_PASSWORD=user_password

# ─── Product Service DB ───────────────────────────
PRODUCT_DB_HOST=product-db
PRODUCT_DB_PORT=5432
PRODUCT_DB_DATABASE=product_service
PRODUCT_DB_USERNAME=product_user
PRODUCT_DB_PASSWORD=product_password

# ─── Order Service DB ────────────────────────────
ORDER_DB_HOST=order-db
ORDER_DB_PORT=5432
ORDER_DB_DATABASE=order_service
ORDER_DB_USERNAME=order_user
ORDER_DB_PASSWORD=order_password

# ─── Kafka ────────────────────────────────────────
KAFKA_BROKER=kafka:9092

# ─── Service Ports (host) ────────────────────────
GATEWAY_PORT=8000
USER_SERVICE_PORT=8001
PRODUCT_SERVICE_PORT=8002
ORDER_SERVICE_PORT=8003
```

---

## Step 1.5 — Verify Everything Boots

Run from the project root:

```bash
# Copy env file
cp .env.example .env

# Build and start all containers
docker compose up --build -d

# Check all containers are running
docker compose ps

# Expected: all containers show "Up" status
# Test Kafka UI (optional)
# Open http://localhost:8080 in browser
```

**Troubleshooting:**
- If Kafka fails to start, ensure Zookeeper is healthy first: `docker compose logs zookeeper`
- If PHP-FPM containers exit, check if the service directories exist (they won't until Phase 2-4)
- For now, it's OK if PHP-FPM containers fail — the Laravel projects don't exist yet

**Verify Kafka is working:**
```bash
# Enter Kafka container
docker compose exec kafka bash

# Create a test topic
kafka-topics --create --topic test --bootstrap-server localhost:9092 --partitions 1 --replication-factor 1

# List topics
kafka-topics --list --bootstrap-server localhost:9092

# Exit
exit
```

---

## ✅ Phase 1 Complete Checklist

- [ ] `docker/php/Dockerfile` created with PHP 8.3-FPM + rdkafka
- [ ] 4 Nginx config files created in `docker/nginx/`
- [ ] `docker-compose.yml` with all services defined
- [ ] `.env.example` created at project root
- [ ] `docker compose up --build` runs without errors
- [ ] Kafka UI accessible at `http://localhost:8080`
- [ ] Can create/list Kafka topics from container

---

**Next:** [Phase 2 — User Service](./phase-2-user-service.md)
