<?php

declare(strict_types=1);

namespace App\Tests\Unit\Settings;

use App\Settings\LocalSettings;
use App\Settings\SettingsEnvVarLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LocalSettingsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mystrava-settings-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->dir);
    }

    public function testUnresolvedDataDirIsNeverWritten(): void
    {
        // Nom de variable reçu pendant la compilation du conteneur, avant résolution.
        $placeholder = 'env_c93fade69c9d956b_default_app_default_data_dir_MYSTRAVA_DATA_DIR_'.bin2hex(random_bytes(4));

        $vars = new SettingsEnvVarLoader(new LocalSettings($placeholder))->loadEnvVars();

        self::assertSame([], $vars);
        self::assertDirectoryDoesNotExist($placeholder);
    }

    public function testKeysAreSavedClearedAndExposedAsEnvVars(): void
    {
        $settings = new LocalSettings($this->dir);
        self::assertSame('', $settings->get('INTERVALS_API_KEY'));

        $settings->save(['INTERVALS_API_KEY' => '  abc123  ']);
        self::assertSame('abc123', new LocalSettings($this->dir)->get('INTERVALS_API_KEY'));

        $vars = new SettingsEnvVarLoader($settings)->loadEnvVars();
        self::assertSame('abc123', $vars['INTERVALS_API_KEY']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $vars['APP_SECRET']);
        // Le secret est généré une fois puis conservé.
        self::assertSame($vars['APP_SECRET'], $settings->all()['APP_SECRET']);

        $settings->save(['INTERVALS_API_KEY' => '']);
        self::assertArrayNotHasKey('INTERVALS_API_KEY', $settings->all());
    }

    public function testUnknownKeyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalSettings($this->dir)->save(['DATABASE_URL' => 'x']);
    }

    public function testCorruptedFileIsIgnored(): void
    {
        new Filesystem()->dumpFile($this->dir.'/settings.json', '{pas du json');

        self::assertSame('', new LocalSettings($this->dir)->get('ANTHROPIC_API_KEY'));
    }
}
