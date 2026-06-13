<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Выбрасывается когда парсинг Яндекс.Карт не удался по конкретной причине.
 * Сообщение сохраняется в organizations.sync_error и отображается в UI.
 */
class YandexParseException extends RuntimeException {}
