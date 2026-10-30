<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Sənəd əməliyyatı qadağandır (bağlanmış dövr, artıq ləğv edilib və s.). Veb sorğuda istifadəçi
 * əvvəlki səhifəyə mesajla qaytarılır (bootstrap/app.php).
 */
class DocumentActionException extends RuntimeException {}
