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

USER adminer
