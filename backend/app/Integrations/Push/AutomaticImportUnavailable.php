<?php

namespace App\Integrations\Push;

use RuntimeException;

/**
 * The store is connected in a way that cannot notify Finovo (e.g. by database details or an API key, or without
 * the latest Finovo module). Not a failure: Finovo keeps reading its new orders every minute.
 */
class AutomaticImportUnavailable extends RuntimeException {}
