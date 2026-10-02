<?php

namespace App\Domain\Media;

use RuntimeException;

/** Imagen rechazada al subirla; el mensaje es para la persona que la subió. */
final class ImageRejected extends RuntimeException {}
