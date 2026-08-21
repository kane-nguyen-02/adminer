<?php

/**
 * Enables Adminer's bundled Redis driver (plugins/drivers/redis.php,
 * already shipped inside the adminer:latest image — nothing downloaded).
 * See mongo.php in this directory for why the file is structured this way.
 *
 * Unlike Mongo, this driver talks to Redis/KeyDB over a raw PHP socket
 * (fsockopen) — no PHP extension needed, works with the stock image.
 */
require_once('plugins/drivers/redis.php');

return new class extends Adminer\Plugin {};
