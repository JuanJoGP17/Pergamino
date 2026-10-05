<?php

namespace App\Domain\Sheet;

use RuntimeException;

/** Algo al compartir una hoja que no se puede hacer; el mensaje es para la persona. */
final class SharingException extends RuntimeException {}
