<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Import\ActivityImporter;
use App\Tests\StravaExportFactory;
use App\Tui\MyRunsApp;
use Revolt\EventLoop;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * Pilote la TUI dans un terminal virtuel : menu → activités → objectif → prédiction.
 */
final class TuiTest extends KernelTestCase
{
    private const ENTER = "\r";
    private const DOWN = "\e[B";
    private const CTRL_C = "\x03";

    public function testGoalWizardDisplaysProbability(): void
    {
        self::bootKernel();
        $workDir = sys_get_temp_dir().'/myruns-tui-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        self::getContainer()->get(ActivityImporter::class)->import(\dirname(StravaExportFactory::create($workDir)));
        $filesystem->remove($workDir);

        $terminal = new VirtualTerminal(120, 40);
        $screens = [];
        $capture = static function () use ($terminal): string {
            $buffer = new ScreenBuffer(120, 40);
            $buffer->write($terminal->getOutput());

            return $buffer->getScreen();
        };

        $goalDate = (new \DateTimeImmutable('+10 weeks'))->format('Y-m-d');
        $steps = [
            ['', 'menu'],
            [self::ENTER, 'activities'],
            [self::ENTER, null],             // retour au menu
            [self::DOWN.self::DOWN.self::ENTER, null],
            ['10k'.self::ENTER, null],
            ['45:00'.self::ENTER, null],
            [$goalDate.self::ENTER, 'prediction'],
            [self::CTRL_C, null],
        ];

        $delay = 0.05;
        foreach ($steps as [$keys, $name]) {
            EventLoop::delay($delay, static function () use ($terminal, $keys, $name, $capture, &$screens): void {
                foreach (self::splitKeys($keys) as $key) {
                    $terminal->simulateInput($key);
                }
                if (null !== $name) {
                    EventLoop::delay(0.05, static function () use ($name, $capture, &$screens): void {
                        $screens[$name] = $capture();
                    });
                }
            });
            $delay += 0.15;
        }

        self::getContainer()->get(MyRunsApp::class)->run($terminal);

        self::assertStringContainsString('MyRuns · 36 activité(s)', $screens['menu']);
        self::assertStringContainsString('Objectif & prédiction', $screens['menu']);
        self::assertStringContainsString('Potentiel actuel', $screens['menu']);
        self::assertStringContainsString('Séance seuil', $screens['activities']);
        self::assertMatchesRegularExpression('/Probabilité de réussite : \d+ %/', $screens['prediction']);
        self::assertStringContainsString('Analyse IA', $screens['prediction']);
    }

    /**
     * Découpe une saisie en touches : séquences d'échappement entières, caractères un par un.
     *
     * @return list<string>
     */
    private static function splitKeys(string $keys): array
    {
        preg_match_all('/\e\[[A-D]|./su', $keys, $matches);

        return $matches[0];
    }
}
