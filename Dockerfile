# Pinned to an exact patch release: 8.4.27 fixes PHP2Shell
# (CVE-2026-109618 / CVE-2026-109619, unauthenticated RCE against mod_php).
FROM php:8.4.27-apache

# ── System dependencies for PHP extensions ────────────────────────────────────
# libonig-dev is required by mbstring
RUN apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ─────────────────────────────────────────────────────────────
RUN docker-php-ext-install mysqli mbstring

# Refuse to build on a PHP release vulnerable to CVE-2026-109618 / CVE-2026-109619
RUN php -r 'exit(version_compare(PHP_VERSION, "8.4.27", ">=") ? 0 : 1);' \
 || (echo "ERROR: PHP $(php -r 'echo PHP_VERSION;') is vulnerable to PHP2Shell; need >= 8.4.27" >&2; exit 1)

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
