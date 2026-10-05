<?php

namespace App\Domain\Catalog;

use RuntimeException;

/** Una acción del catálogo que no se puede hacer; el mensaje es para la persona. */
final class CatalogException extends RuntimeException {}
