FROM php:8.2-apache

# ── PHP extensions ─────────────────────────────────────────────────────────────
RUN docker-php-ext-install mysqli mbstring

# ── Apache: enable mod_rewrite (for future .htaccess use) ─────────────────────
RUN a2enmod rewrite

# ── Apache: trust X-Forwarded-* headers from Traefik ─────────────────────────
RUN a2enmod remoteip \
 && printf '<IfModule remoteip_module>\n\
    RemoteIPHeader X-Forwarded-For\n\
    RemoteIPTrustedProxy 172.16.0.0/12\n\
</IfModule>\n' > /etc/apache2/conf-available/remoteip.conf \
 && a2enconf remoteip

# ── App files ──────────────────────────────────────────────────────────────────
WORKDIR /var/www/html

# Copy everything except what's listed in .dockerignore
COPY --chown=www-data:www-data . .

# Remove any accidentally copied secrets / dev-only files
RUN rm -f creds.php .env docker/.env || true

# ── Entrypoint ─────────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
