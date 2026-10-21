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

# ── PHP: production ini + hardening ───────────────────────────────────────────
# Without a php.ini the built-in defaults apply (display_errors=On, expose_php=On),
# which leaks file paths and the PHP version to anyone.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf '%s\n' \
      'expose_php = Off' \
      'display_errors = Off' \
      'display_startup_errors = Off' \
      'log_errors = On' \
      'session.use_strict_mode = 1' \
      'session.cookie_httponly = 1' \
    > "$PHP_INI_DIR/conf.d/zz-torque-hardening.ini"

# ── Apache: enable mod_rewrite (for future .htaccess use) ─────────────────────
RUN a2enmod rewrite

# ── Apache: trust X-Forwarded-* headers from Traefik ─────────────────────────
RUN a2enmod remoteip \
 && printf '<IfModule remoteip_module>\n\
    RemoteIPHeader X-Forwarded-For\n\
    RemoteIPTrustedProxy 172.16.0.0/12\n\
</IfModule>\n' > /etc/apache2/conf-available/remoteip.conf \
 && a2enconf remoteip

# ── Apache: hide version, block non-public paths ──────────────────────────────
# Defence in depth on top of .dockerignore: even if these paths exist in the
# image (or a volume is mounted there) they are never served over HTTP.
RUN printf '%s\n' \
      'ServerTokens Prod' \
      'ServerSignature Off' \
      'TraceEnable Off' \
      '<DirectoryMatch "^/var/www/html/(\.git|data|docker|docs|gps|tests)(/|$)">' \
      '    Require all denied' \
      '</DirectoryMatch>' \
      '<FilesMatch "(^\.|\.(md|yml|yaml|sh|py|log|example|example\.php|dist)$|^Dockerfile$|^creds\.php$)">' \
      '    Require all denied' \
      '</FilesMatch>' \
    > /etc/apache2/conf-available/zz-torque-security.conf \
 && a2enconf zz-torque-security

# ── App files ──────────────────────────────────────────────────────────────────
WORKDIR /var/www/html

# Copy everything except what's listed in .dockerignore.
# Code is owned by root and NOT writable by the web server (www-data), so a
# file-write bug can't be turned into persistent code execution. entrypoint.sh
# (running as root) writes creds.php at startup.
COPY --chown=root:root . .

# Remove any accidentally copied secrets / dev-only files; create the log dir
# outside the web root (see torque_log_dir() in db.php)
RUN rm -rf creds.php .env docker \
 && chown root:root /var/www/html \
 && find /var/www/html -type d -exec chmod 755 {} + \
 && find /var/www/html -type f -exec chmod 644 {} + \
 && mkdir -p /var/log/torque \
 && chown www-data:www-data /var/log/torque \
 && chmod 750 /var/log/torque

# ── Entrypoint ─────────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
