<?php

/**
 * Enables Adminer's bundled MongoDB driver (plugins/drivers/mongo.php,
 * already shipped inside the adminer:latest image — nothing downloaded).
 *
 * Driver plugins self-register via a top-level add_driver() call and don't
 * expose a normal Adminer\Plugin subclass to instantiate, so this file
 * can't just be one of the usual "return new SomePlugin()" plugins the
 * docker image's plugins-enabled/ convention expects. require_once() below
 * is what actually enables the driver (as a side effect of loading it);
 * the anonymous Plugin instance returned after it only exists so Adminer
 * core doesn't flag this file's require() result as "not a valid plugin"
 * (Adminer\Plugins::__construct warns — via lang(90) — about any
 * plugins-enabled entry that isn't a plugin object).
 *
 * Requires the `mongodb` PHP extension to actually connect — see
 * adminer/Dockerfile, which builds it into this image.
 */
require_once('plugins/drivers/mongo.php');

return new class extends Adminer\Plugin {};
