<?php

namespace App\Exceptions\Provider;

/**
 * The provider rejected the message (4xx, invalid response). Retrying will not help.
 */
class PermanentProviderException extends ProviderException {}
