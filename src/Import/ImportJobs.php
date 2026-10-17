<?php

declare(strict_types=1);

namespace App\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Imports web en cours, gardés dans le dossier de données (un fichier JSON chacun) : chaque
 * requête lit les traces quelques secondes, la page enchaîne les étapes et peut reprendre plus
 * tard sans renvoyer l'archive.
 */
final class ImportJobs
{
    /** Au-delà, un import abandonné (page fermée) est effacé avec son archive. */
    private const MAX_AGE = '-7 days';
    private const SUFFIX = '.import.json';

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly ActivityImporter $importer,
        #[Autowire('%app.data_dir%/uploads')] private readonly string $directory,
    ) {
        $this->filesystem = new Filesystem();
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function create(string $path, bool $temporary, ?string $id = null): ImportJob
    {
        return new ImportJob($id ?? self::newId(), $path, $temporary, new \DateTimeImmutable());
    }

    public function find(string $id): ?ImportJob
    {
        $file = $this->file($id);
        if (null === $file || !is_file($file)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? ImportJob::fromArray($data) : null;
    }

    /**
     * Imports inachevés, le plus récent d'abord ; ceux abandonnés depuis longtemps sont effacés.
     *
     * @return list<ImportJob>
     */
    public function pending(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $jobs = [];
        $limit = new \DateTimeImmutable(self::MAX_AGE);
        foreach ((new Finder())->files()->in($this->directory)->depth(0)->name('*'.self::SUFFIX) as $file) {
            $job = $this->find($file->getBasename(self::SUFFIX));
            if (null === $job) {
                continue;
            }
            if ($job->startedAt < $limit) {
                $this->delete($job);
                continue;
            }
            $jobs[] = $job;
        }
        usort($jobs, static fn (ImportJob $a, ImportJob $b): int => $b->startedAt <=> $a->startedAt);

        return $jobs;
    }

    /**
     * Fait avancer l'import pendant environ $seconds (au moins une trace), puis l'enregistre,
     * ou l'efface s'il est terminé.
     *
     * @throws \InvalidArgumentException|\RuntimeException fichier illisible : l'import est effacé
     */
    public function step(ImportJob $job, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;
        $saved = null !== $job->total;
        try {
            if (null === $job->total) {
                $job->total = $this->importer->begin($job->path, $job->result);
            }
            if ($job->done < $job->total) {
                $next = $this->importer->continue($job->path, $job->done, $job->result, $deadline);
                // Traces supprimées du dossier entre deux étapes : rien de plus à lire.
                $job->done = $next > $job->done ? $next : $job->total;
            }
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->delete($job);

            throw $e;
        }

        if ($job->isFinished()) {
            $this->delete($job);
        } elseif (!$saved || null !== $this->find($job->id)) {
            // Sinon, abandonné pendant l'étape (autre onglet, bouton « Arrêter ») : on ne le recrée pas.
            $this->save($job);
        }
    }

    public function delete(ImportJob $job): void
    {
        $file = $this->file($job->id);
        if (null !== $file) {
            $this->filesystem->remove($file);
        }
        if ($job->temporary) {
            $this->filesystem->remove($job->path);
        }
    }

    private function save(ImportJob $job): void
    {
        $file = $this->file($job->id);
        if (null !== $file) {
            $this->filesystem->dumpFile($file, json_encode($job->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @return string|null null pour un identifiant mal formé (jamais de chemin construit depuis l'URL)
     */
    private function file(string $id): ?string
    {
        return 1 === preg_match('/^[0-9a-f]{16}$/', $id) ? $this->directory.'/'.$id.self::SUFFIX : null;
    }
}
