# Adds the mongodb PHP extension on top of the official adminer image so
# Adminer's bundled Mongo driver plugin (plugins/drivers/mongo.php) can
# actually connect, not just appear in the driver dropdown.
# Redis needs no extension (its driver talks raw sockets in pure PHP).
# Pin to 6.0.1 (same as runtime ADMINER_VERSION) — avoid silent upstream drift from :latest.
FROM adminer:6.0.1

# The base image drops to the unprivileged `adminer` user; installing a
# PHP extension needs root, then we hand control back to that same user
# so the container's runtime privileges are unchanged from upstream.
USER root

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS openssl-dev \
	&& pecl install mongodb \
	&& docker-php-ext-enable mongodb \
	&& apk del .build-deps

# Upstream bug in the bundled Redis driver under PHP 8.4: it narrows
# SqlDriver::jushAutocomplete()'s untyped 2nd parameter to `?array`, which
# is a fatal "Declaration ... must be compatible" error under PHP's
# stricter parameter-type covariance checks. Confirmed by actually logging
# in with the Redis driver before this patch — reproducible, not
# hypothetical. Same bug also exists in the (unused here) igdb/simpledb
# drivers; only patching the one we enable.
RUN sed -i \
	"s/function jushAutocomplete(array \$tables, ?array \$statements): string {/function jushAutocomplete(array \$tables, \$statements): string {/" \
	plugins/drivers/redis.php

# Upstream bug: the export loop terminates its `DROP TYPE` / `DROP FUNCTION` /
# `DROP EVENT` guards with `;;` unconditionally - the MySQL DELIMITER token -
# even though only `JUSH=='sql'` gets wrapped in the matching
# "DELIMITER ;;" ... "DELIMITER ;" pair. A PostgreSQL dump therefore carries a
# bare `;;`, and Adminer's own statement splitter has no empty-statement guard:
#
#     $Ti = substr($G, 0, $F + ($Rb?3:0));   // '' on the second semicolon
#     ... connection()->multi_query($Ti)
#
# so importing such a dump dies with
# "PDO::query(): Argument #1 ($query) must not be empty" under PHP 8.4.
# Reproduced by exporting f3s with types+routines and importing it back.
#
# It stayed hidden while the export used db_style=DROP+CREATE, because that
# option suppresses those DROP lines entirely (and produces an unimportable
# `\connect` header instead - see plugins-enabled/dump-pgsql-fix.php, which
# forces db_style blank and is what surfaced this).
#
# str_replace on the exact literal rather than sed: adminer.php is minified, and
# a regex over it is far easier to get subtly wrong. The build fails if the
# occurrence count is not 3, so a version bump cannot silently skip the patch.
RUN php -r '$f = "adminer.php"; \
	$from = ".\";;\\n\":\"\")"; \
	$to = ".(JUSH==\x27sql\x27?\";;\":\";\").\"\\n\":\"\")"; \
	$n = 0; \
	$s = str_replace($from, $to, file_get_contents($f), $n); \
	if ($n !== 3) { fwrite(STDERR, "adminer.php \";;\" patch: expected 3 sites, matched $n\n"); exit(1); } \
	file_put_contents($f, $s);'

# Real backup/restore needs the real tools. Adminer's SQL-text dump cannot round
# trip this schema - measured against f3s it restores with 149 errors: booleans
# dumped as 0/1, enum defaults emitted unquoted, partition children never
# re-attached, `ALTER TABLE ONLY` foreign keys on partitioned parents, and no
# CREATE EXTENSION so a gin_trgm_ops index has no operator class. pg_dump -Fc
# carries all of that, and `pg_restore --single-transaction` is atomic, which
# statement-at-a-time SQL import through PHP can never be.
#
# postgresql18-client, not the generic postgresql-client: pg_dump refuses to dump
# from a server newer than itself, and these servers are PostgreSQL 18.x. Bump
# this together with the servers.
RUN apk add --no-cache postgresql18-client

# Backups live on a named volume. Created here, while still root, for the same
# reason /sessions is: Docker seeds a fresh named volume from the image's
# ownership at the mount point, so a path that does not exist in the image
# arrives root-owned 0755 and uid 100 cannot write it.
RUN mkdir -p /backups && chown adminer:adminer /backups

# Session storage lives on a named volume so logins outlive the container.
# Created here, while still root, because Docker seeds a fresh named volume from
# the image's contents AND ownership at the mount point - mount an empty volume
# on a path that does not exist in the image and it arrives root-owned 0755,
# which uid 100 cannot write, and PHP silently stops persisting sessions.
RUN mkdir -p /sessions && chown adminer:adminer /sessions

USER adminer
