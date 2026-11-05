<?php

declare(strict_types=1);

namespace App\Backup;

/**
 * Sauvegarde refusée : le message est affiché tel quel à l'utilisateur.
 */
final class BackupException extends \RuntimeException
{
}
