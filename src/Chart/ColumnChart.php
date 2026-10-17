<?php

declare(strict_types=1);

namespace App\Chart;

/**
 * Géométrie d'un histogramme à une série (colonnes), calculée côté serveur et dessinée en SVG
 * par le template. Les colonnes partent d'une ligne de base unique, sommet arrondi de 4 px.
 */
final readonly class ColumnChart
{
    // Largeur proche de la taille affichée : les libellés gardent ~12 px à l'écran.
    public const WIDTH = 480;
    public const HEIGHT = 220;
    private const PADDING_LEFT = 32;
    private const PADDING_RIGHT = 8;
    private const PADDING_TOP = 20;
    private const PADDING_BOTTOM = 28;
    private const MAX_COLUMN_WIDTH = 24;
    private const RADIUS = 4;

    /**
     * @param list<array{label: string, value: float, x: float, y: float, width: float, height: float, path: string, tick: bool, highlight: bool}> $columns
     * @param list<array{value: float, y: float}>                                                                                                  $gridlines
     */
    private function __construct(
        public array $columns,
        public array $gridlines,
        public float $baselineY,
        public float $plotLeft,
        public float $plotRight,
    ) {
    }

    /**
     * @param array<string, float> $series    libellé → valeur, dans l'ordre d'affichage
     * @param int                  $tickEvery un libellé d'abscisse toutes les N colonnes
     */
    public static function fromSeries(array $series, int $tickEvery = 4): self
    {
        $plotWidth = self::WIDTH - self::PADDING_LEFT - self::PADDING_RIGHT;
        $plotHeight = self::HEIGHT - self::PADDING_TOP - self::PADDING_BOTTOM;
        $baseline = self::PADDING_TOP + $plotHeight;

        $max = self::niceMax([] === $series ? 0.0 : max($series));
        $count = max(1, \count($series));
        $slot = $plotWidth / $count;
        // Colonnes plafonnées à 24 px, avec au moins 2 px de vide entre deux colonnes.
        $width = min(self::MAX_COLUMN_WIDTH, $slot - 2);

        $columns = [];
        $i = 0;
        $lastIndex = $count - 1;
        $peak = [] === $series ? null : array_search(max($series), $series, true);
        foreach ($series as $label => $value) {
            $height = $max > 0 ? $value / $max * $plotHeight : 0.0;
            $x = self::PADDING_LEFT + $i * $slot + ($slot - $width) / 2;
            $y = $baseline - $height;
            $columns[] = [
                'label' => (string) $label,
                'value' => $value,
                'x' => round($x, 2),
                'y' => round($y, 2),
                'width' => round($width, 2),
                'height' => round($height, 2),
                'path' => self::roundedTopPath($x, $y, $width, $height),
                'tick' => 0 === ($lastIndex - $i) % $tickEvery,
                'highlight' => $i === $lastIndex || $label === $peak,
            ];
            ++$i;
        }

        $gridlines = [];
        for ($step = 0; $step <= 4; ++$step) {
            $value = $max * $step / 4;
            $gridlines[] = ['value' => $value, 'y' => round($baseline - $value / $max * $plotHeight, 2)];
        }

        return new self($columns, $gridlines, $baseline, self::PADDING_LEFT, self::WIDTH - self::PADDING_RIGHT);
    }

    /**
     * Arrondit le maximum de l'axe à une graduation lisible (10, 20, 25, 40, 50, 80, 100…).
     */
    private static function niceMax(float $value): float
    {
        if ($value <= 0) {
            return 10.0;
        }

        $magnitude = 10 ** floor(log10($value));
        foreach ([1, 2, 2.5, 4, 5, 8, 10] as $factor) {
            if ($value <= $factor * $magnitude) {
                return $factor * $magnitude;
            }
        }

        return 10 * $magnitude;
    }

    /**
     * Rectangle au sommet arrondi et à la base carrée (ancrée sur la ligne de base).
     */
    private static function roundedTopPath(float $x, float $y, float $width, float $height): string
    {
        if ($height <= 0) {
            return '';
        }

        $r = min(self::RADIUS, $width / 2, $height);
        $bottom = $y + $height;

        return \sprintf(
            'M%.2F %.2F V%.2F Q%.2F %.2F %.2F %.2F H%.2F Q%.2F %.2F %.2F %.2F V%.2F Z',
            $x, $bottom,
            $y + $r,
            $x, $y, $x + $r, $y,
            $x + $width - $r,
            $x + $width, $y, $x + $width, $y + $r,
            $bottom,
        );
    }
}
