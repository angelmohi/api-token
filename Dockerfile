# 1. Usa PHP con cURL
FROM php:8.1-cli

# 2. Copia y sobreescribe la configuración global de OpenSSL
COPY openssl.cnf /etc/ssl/openssl.cnf

# 3. Copia tu proxy
WORKDIR /app
COPY index.php .

# 4. Expone el puerto 8080
EXPOSE 8080

# 5. Arranca el servidor embebido de PHP
CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
