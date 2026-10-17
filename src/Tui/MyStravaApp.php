<?php

declare(strict_types=1);

namespace App\Tui;

use App\Ai\AiCoach;
use App\Entity\Activity;
use App\Import\ActivityImporter;
use App\Prediction\FitnessAnalyzer;
use App\Prediction\Goal;
use App\Prediction\GoalPredictor;
use App\Prediction\Prediction;
use App\Prediction\PredictionFormatter;
use App\Repository\ActivityRepository;
use App\Util\Units;
use Revolt\EventLoop;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\TailwindStylesheet;
use Symfony\Component\Tui\Terminal\Terminal;
use Symfony\Component\Tui\Terminal\TerminalInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\LoaderWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Interface terminal : tableau de bord, liste des activités, import et prédiction d'objectif.
 */
final class MyStravaApp
{
    private const GOAL_STEPS = [
        'distance' => 'Distance (5k, 10, 21.1, semi, marathon) : ',
        'time' => 'Temps visé (45:00, 1:45:00, 3h30) : ',
        'date' => 'Date de l\'objectif (2026-12-15 ou 15/12/2026) : ',
    ];

    private Tui $tui;
    private TextWidget $header;
    private TextWidget $fitness;
    private ContainerWidget $body;
    private TextWidget $status;

    /** @var array<string, string> saisies de l'assistant d'objectif */
    private array $goalInput = [];
    private ?Prediction $prediction = null;

    public function __construct(
        private readonly ActivityRepository $activities,
        private readonly ActivityImporter $importer,
        private readonly FitnessAnalyzer $analyzer,
        private readonly GoalPredictor $predictor,
        private readonly PredictionFormatter $formatter,
        private readonly AiCoach $coach,
        #[Autowire('%env(STRAVA_EXPORT_PATH)%')] private readonly string $defaultImportPath,
    ) {
    }

    public function run(TerminalInterface $terminal = new Terminal()): void
    {
        $this->tui = new Tui(new TailwindStylesheet([
            ':root' => new Style(gap: 1),
        ]), $terminal);

        $keys = new Keybindings(['quit' => ['ctrl+c']]);
        $this->tui->addListener(function (InputEvent $event) use ($keys): void {
            if ($keys->matches($event->getData(), 'quit')) {
                $this->tui->stop();
            }
        });

        $this->header = $this->styled(new TextWidget(), 'bold text-orange-500');
        $this->fitness = $this->styled(new TextWidget(), 'text-sky-300 border border-rounded border-slate-600 px-1');
        $this->body = new ContainerWidget();
        $this->status = $this->styled(new TextWidget(), 'text-amber-400');

        $this->tui
            ->add($this->header)
            ->add($this->fitness)
            ->add($this->body)
            ->add($this->status)
            ->add($this->styled(new TextWidget('↑/↓ naviguer · Entrée valider · Échap retour · Ctrl+C quitter'), 'dim'));

        $this->refreshDashboard();
        $this->showMenu();

        $this->tui->run();
    }

    private function refreshDashboard(): void
    {
        $last = $this->activities->findMostRecent();
        $this->header->setText(\sprintf(
            'MyStrava · %d activité(s)%s',
            $this->activities->count(),
            null !== $last ? ' · dernière le '.$last->startDate->format('d/m/Y') : ' · aucune donnée, commencez par « Importer »',
        ));
        $this->fitness->setText(implode("\n", $this->formatter->fitnessLines($this->analyzer->analyze())));
    }

    private function showMenu(): void
    {
        $menu = new SelectListWidget([
            ['value' => 'activities', 'label' => 'Activités récentes', 'description' => 'Les dernières sorties importées'],
            ['value' => 'import', 'label' => 'Importer', 'description' => 'Export Strava (ZIP ou dossier) ou dossier GPX/TCX'],
            ['value' => 'goal', 'label' => 'Objectif & prédiction', 'description' => 'Probabilité de réussir un chrono à une date'],
            ['value' => 'quit', 'label' => 'Quitter', 'description' => ''],
        ], maxVisible: 6);
        $menu->onSelect(function (SelectEvent $event): void {
            match ($event->getValue()) {
                'activities' => $this->showActivities(),
                'import' => $this->showImport(),
                'goal' => $this->startGoal(),
                default => $this->tui->stop(),
            };
        });
        $menu->onCancel(fn () => $this->tui->stop());

        $this->setBody($menu);
    }

    private function showActivities(): void
    {
        $items = array_map(static fn (Activity $a): array => [
            'value' => $a->id,
            'label' => \sprintf('%s  %-10s %9s', $a->startDate->format('d/m/y'), $a->sport->label(), Units::formatDistance($a->distanceMeters)),
            'description' => implode(' · ', array_filter([
                Units::formatDuration($a->movingTimeSeconds),
                $a->sport->isRunning() ? Units::formatPace($a->paceSecondsPerKm()) : null,
                null !== $a->effectiveElevationGain() ? \sprintf('D+ %d m', $a->effectiveElevationGain()) : null,
                null !== $a->averageHeartRate ? \sprintf('FC %d', $a->averageHeartRate) : null,
                $a->name,
            ])),
        ], $this->activities->findLatest(200));

        if ([] === $items) {
            $this->setStatus('Aucune activité : lancez un import.');

            return;
        }

        $list = new SelectListWidget($items, maxVisible: 15);
        $list->onCancel(fn () => $this->showMenu());
        $list->onSelect(fn () => $this->showMenu());
        $this->setBody($list, \sprintf('%d activité(s) — Échap pour revenir au menu', \count($items)));
    }

    private function showImport(): void
    {
        $input = new InputWidget();
        $input->setPrompt('Chemin : ');
        $input->setValue($this->defaultImportPath);
        $input->onCancel(fn () => $this->showMenu());
        $input->onSubmit(function (SubmitEvent $event): void {
            $path = $this->expandHome(trim($event->getValue()));
            $this->runInBackground('Import en cours…', function () use ($path): void {
                $result = $this->importer->import($path);
                $this->refreshDashboard();
                $this->showMenu();
                $this->setStatus('Import terminé : '.$result->summary().([] !== $result->errors ? ' — '.$result->errors[0] : ''));
            }, fn () => $this->showImport());
        });

        $this->setBody($input, 'Archive export_XXXX.zip, dossier décompressé ou dossier de traces GPX/TCX.');
    }

    private function startGoal(): void
    {
        $this->goalInput = [];
        $this->prediction = null;
        $this->askGoalStep();
    }

    private function askGoalStep(?string $error = null): void
    {
        $step = array_key_first(array_diff_key(self::GOAL_STEPS, $this->goalInput));
        if (null === $step) {
            $this->computePrediction();

            return;
        }

        $input = new InputWidget();
        $input->setPrompt(self::GOAL_STEPS[$step]);
        $input->onCancel(fn () => $this->showMenu());
        $input->onSubmit(function (SubmitEvent $event) use ($step): void {
            $value = trim($event->getValue());
            try {
                match ($step) {
                    'distance' => Units::parseDistance($value),
                    'time' => Units::parseDuration($value),
                    default => Units::parseDate($value, new \DateTimeImmutable()),
                };
            } catch (\InvalidArgumentException $e) {
                $this->askGoalStep($e->getMessage());

                return;
            }
            $this->goalInput[$step] = $value;
            $this->askGoalStep();
        });

        $this->setBody($input, $error ?? \sprintf('Étape %d/3', \count($this->goalInput) + 1));
    }

    private function computePrediction(): void
    {
        $goal = new Goal(
            Units::parseDistance($this->goalInput['distance']),
            Units::parseDuration($this->goalInput['time']),
            Units::parseDate($this->goalInput['date'], new \DateTimeImmutable()),
        );

        $this->prediction = $this->predictor->predict($goal);
        if (null === $this->prediction) {
            $this->showMenu();
            $this->setStatus('Pas assez de sorties de course sur les 12 dernières semaines pour prédire.');

            return;
        }

        $this->showPrediction($this->formatter->predictionLines($this->prediction));
    }

    /**
     * @param list<string> $lines
     */
    private function showPrediction(array $lines): void
    {
        $container = $this->styled(new ContainerWidget(), 'gap-1');
        $container->add($this->styled(new TextWidget(implode("\n", $lines)), 'border border-rounded border-emerald-600 px-1'));

        $actions = new SelectListWidget([
            ['value' => 'ai', 'label' => 'Analyse IA', 'description' => 'Affiner avec le coach IA (Symfony AI)'],
            ['value' => 'new', 'label' => 'Nouvel objectif', 'description' => ''],
            ['value' => 'menu', 'label' => 'Retour au menu', 'description' => ''],
        ]);
        $actions->onCancel(fn () => $this->showMenu());
        $actions->onSelect(function (SelectEvent $event) use ($lines): void {
            match ($event->getValue()) {
                'ai' => $this->runAiAnalysis($lines),
                'new' => $this->startGoal(),
                default => $this->showMenu(),
            };
        });
        $container->add($actions);

        $this->setBody($container);
        $this->tui->setFocus($actions);
    }

    /**
     * @param list<string> $statisticalLines
     */
    private function runAiAnalysis(array $statisticalLines): void
    {
        $prediction = $this->prediction;
        if (null === $prediction) {
            return;
        }

        $this->runInBackground('Le coach IA analyse votre entraînement…', function () use ($prediction, $statisticalLines): void {
            $analysis = $this->coach->analyze($prediction);
            $this->showPrediction([...$statisticalLines, '', ...$this->formatter->analysisLines($analysis)]);
            $this->setStatus('');
        }, fn () => $this->showPrediction($statisticalLines));
    }

    /**
     * Affiche un indicateur d'attente, puis exécute la tâche (bloquante) au tick suivant
     * pour que l'écran soit rafraîchi avant.
     *
     * @param \Closure(): void $task
     * @param \Closure(): void $onError
     */
    private function runInBackground(string $message, \Closure $task, \Closure $onError): void
    {
        $loader = new LoaderWidget($message);
        $this->setBody($loader);
        $loader->start();
        $this->tui->requestRender(true);

        EventLoop::delay(0.1, function () use ($loader, $task, $onError): void {
            try {
                $task();
            } catch (\Throwable $e) {
                $onError();
                $this->setStatus('Erreur : '.$e->getMessage());
            } finally {
                $loader->stop();
                $this->tui->requestRender();
            }
        });
    }

    private function setBody(AbstractWidget $widget, string $status = ''): void
    {
        $this->body->clear();
        $this->body->add($widget);
        $this->setStatus($status);
        if ($widget instanceof SelectListWidget || $widget instanceof InputWidget) {
            $this->tui->setFocus($widget);
        }
    }

    private function setStatus(string $message): void
    {
        $this->status->setText($message);
    }

    /**
     * @template T of AbstractWidget
     *
     * @param T $widget
     *
     * @return T
     */
    private function styled(AbstractWidget $widget, string $classes): AbstractWidget
    {
        foreach (explode(' ', $classes) as $class) {
            $widget->addStyleClass($class);
        }

        return $widget;
    }

    private function expandHome(string $path): string
    {
        $home = getenv('HOME');

        return str_starts_with($path, '~/') && \is_string($home) ? $home.substr($path, 1) : $path;
    }
}
