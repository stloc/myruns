<?php

declare(strict_types=1);

// Routeur du serveur web interne de PHP (application de bureau) : les fichiers de public/ sont
// servis tels quels, tout le reste passe par Symfony. Copié à la racine de l'app préparée.

$public = __DIR__.'/public';
$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', \PHP_URL_PATH);
$file = is_string($path) ? realpath($public.rawurldecode($path)) : false;

if (false !== $file && is_file($file) && str_starts_with($file, realpath($public).\DIRECTORY_SEPARATOR) && 'index.php' !== basename($file)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';

require $public.'/index.php';
