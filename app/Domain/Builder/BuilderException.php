<?php

namespace App\Domain\Builder;

use RuntimeException;

/**
 * Una operación del constructor que no se puede hacer. El mensaje va directo
 * a la persona que construye la plantilla, así que debe decir qué hacer.
 */
final class BuilderException extends RuntimeException {}
