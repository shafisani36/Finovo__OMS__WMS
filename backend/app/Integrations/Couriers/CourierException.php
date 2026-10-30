<?php

namespace App\Integrations\Couriers;

use RuntimeException;

/** A courier refused a request or could not be reached; the message is shown to the user. */
class CourierException extends RuntimeException {}
