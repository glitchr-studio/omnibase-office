<?php

namespace Base\Office\Tests\Guard;

use Base\Office\Guard\Wording;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The expressions common to the regulated practices, and a regime's own after them. */
final class WordingTest extends TestCase
{
    #[DataProvider('forbidden')]
    public function testComparisonAndAdvertisingAreFound(string $text, string $label): void
    {
        self::assertContains($label, Wording::scan($text), $text);
    }

    public static function forbidden(): iterable
    {
        yield ['Le meilleur cabinet du canton.', 'le meilleur'];
        yield ['Nos tarifs sont MEILLEURS QUE ceux d’à côté', 'meilleur que'];
        yield ['Cabinet n°1 de la région', 'numéro 1'];
        yield ['Des honoraires moins chers.', 'moins cher'];
        yield ['Plus réactifs que nos confrères', 'plus … que nos confrères'];
        yield ['Un savoir-faire <em>inégalé</em>', 'incomparable'];
        yield ['Résultat garanti', 'résultat garanti'];
    }

    public function testThePlainWordsOfTheTradeAreNot(): void
    {
        foreach (['Nous vous répondons dans les meilleurs délais.', 'Droit de la concurrence et de la distribution.', ''] as $text) {
            self::assertSame([], Wording::scan($text), $text);
        }
        self::assertTrue(Wording::isClean('Rien à signaler.'));
    }

    public function testTheSitesExpressionsAndARegimesPatternsAreAdded(): void
    {
        self::assertSame(['Cabinet de référence'], Wording::scan('Le cabinet de référence du Val.', ['Cabinet de référence']));
        self::assertSame([], Wording::scan('Les références du dossier.', ['Cabinet de référence']));

        $regime = ['ancien juge' => '\bancien(?:ne)?\s+juge\b'];
        self::assertSame(['le meilleur'], Wording::scan('Ancien juge, le meilleur avocat.'));
        self::assertSame(['le meilleur', 'ancien juge'], Wording::scan('Ancien juge, le meilleur avocat.', [], $regime), 'the common ones first, then the regime\'s');
        self::assertFalse(Wording::isClean('Ancienne juge.', [], $regime));
        self::assertTrue(Wording::isClean('Ancienne juge.'));
    }

    public function testPlain(): void
    {
        self::assertSame("l'etude d'a cote", Wording::plain('L’<b>Étude</b>  d’à côté'));
    }
}
