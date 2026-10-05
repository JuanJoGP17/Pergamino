<?php

namespace App\Domain\Campaign;

use RuntimeException;

/** Una operación de mesa que no se puede hacer; el mensaje es para la persona. */
final class CampaignException extends RuntimeException {}
