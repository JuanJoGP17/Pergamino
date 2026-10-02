<?php

namespace App\Domain\Transfer;

use RuntimeException;

/** Un archivo de importación que no se puede usar; el mensaje es para la persona. */
final class TransferException extends RuntimeException {}
