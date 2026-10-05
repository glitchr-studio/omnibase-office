<?php

namespace Base\Office\Tests\DependencyInjection;

use Base\Office\DependencyInjection\OfficeExtension;
use Base\Office\DependencyInjection\Vocabulary;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\Yaml\Yaml;

/** How the practice is named in the bundle's texts: a preset, or its forms; handed to every translation. */
final class VocabularyTest extends TestCase
{
    public function testThePresets(): void
    {
        self::assertSame('le cabinet', Vocabulary::globals()['{the_practice}']);
        self::assertSame(
            ['{practice}' => 'étude', '{the_practice}' => 'l’étude', '{The_practice}' => 'L’étude', '{at_the_practice}' => 'à l’étude', '{At_the_practice}' => 'À l’étude', '{of_the_practice}' => 'de l’étude'],
            Vocabulary::globals('etude')
        );
        self::assertSame('de la maison de santé', Vocabulary::globals('maison_de_sante')['{of_the_practice}']);
        self::assertSame('À l’office', Vocabulary::globals('office')['{At_the_practice}']);
    }

    public function testItsOwnForms(): void
    {
        $globals = Vocabulary::globals(['name' => 'centre de soins']);
        self::assertSame('le centre de soins', $globals['{the_practice}']);
        self::assertSame('Au centre de soins', $globals['{At_the_practice}']);

        $globals = Vocabulary::globals(['name' => 'agence', 'the' => 'l’agence', 'at' => 'à l’agence', 'of' => 'de l’agence']);
        self::assertSame('L’agence', $globals['{The_practice}']);
        self::assertSame('de l’agence', $globals['{of_the_practice}']);
    }

    public function testAnUnknownPresetSaysWhichExist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cabinet, etude, office, maison_de_sante');
        Vocabulary::globals('boutique');
    }

    public function testTheExtensionHandsThemToTheTranslator(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function getAlias(): string { return 'framework'; }
            public function load(array $configs, ContainerBuilder $container): void {}
        });
        $container->registerExtension($extension = new OfficeExtension());
        $container->loadFromExtension('office', ['timezone' => 'Europe/Paris']);
        $container->loadFromExtension('office', ['vocabulary' => ['practice' => 'etude']]);

        $extension->prepend($container);

        self::assertSame('l’étude', $container->getExtensionConfig('framework')[0]['translator']['globals']['{the_practice}']);
    }

    public function testEveryPlaceholderOfTheTextsIsOneOfThem(): void
    {
        $texts = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/office+intl-icu.fr.yaml');
        $known = array_keys(Vocabulary::globals());
        $found = [];
        array_walk_recursive($texts, function ($text) use (&$found) {
            if (\is_string($text) && preg_match_all('/\{(?:[Tt]he_|[Aa]t_the_|of_the_)?practice\}/', $text, $m)) {
                $found = array_merge($found, $m[0]);
            }
        });

        self::assertNotEmpty($found);
        self::assertSame([], array_values(array_diff(array_unique($found), $known)));
        self::assertStringNotContainsString('cabinet', mb_strtolower(json_encode(array_diff_key($texts, ['compliance' => 1]), \JSON_UNESCAPED_UNICODE)), 'no text names the practice by itself (the settings menu apart)');
    }
}
