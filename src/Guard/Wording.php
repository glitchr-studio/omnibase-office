<?php

namespace Base\Office\Guard;

/**
 * Words the communication of a regulated practice must not carry:
 * comparison with colleagues, disparagement, the superlatives of
 * advertising - what the notaries' règlement professionnel (art. 14.1,
 * 14.2) and the lawyers' RIN (art. 10.2) refuse, each in its own terms. A
 * regime cites its texts in its own guard and adds what is its alone
 * (Wording::scan($text, $extra, $patterns)): omnibase/notary,
 * omnibase/lawyer.
 *
 * A list of expressions is a help to the person who writes, not a judge:
 * what it finds is shown as a warning, and what it does not find is not
 * thereby allowed.
 */
class Wording
{
    /** Each a regular expression on the text once lowered and stripped of its accents. */
    public const PATTERNS = [
        'le meilleur' => '\bmeilleur(?:e|s|es)?\s+(?:notaires?|etudes?|offices?|avocat(?:e|s|es)?|cabinets?|specialistes?|experts?|juristes?|services?|tarifs?|honoraires)\b',
        'meilleur que' => '\bmeilleur(?:e|s|es)?\s+qu',
        'numéro 1' => '\b(?:n[o°]\s?1|numero\s+(?:1|un))\b',
        'leader' => '\bleaders?\b',
        'moins cher' => '\bmoins\s+cher(?:e|s|es)?\b',
        'prix imbattable' => '\bimbattables?\b',
        'plus … que nos confrères' => '\bplus\s+\w+\s+que\s+(?:nos|les|vos|d\'autres|des)\s+(?:confreres|autres|notaires|etudes|offices|cabinets|avocats|concurrents)\b',
        'contrairement à' => '\bcontrairement\s+(?:a|aux)\s+(?:nos|d\'autres|certains|certaines|la plupart)\b',
        'nos concurrents' => '\b(?:nos|les|des|ses)\s+concurrents\b',
        'incomparable' => '\b(?:incomparables?|inegal(?:e|ee|es|ees)|sans\s+equivalent)\b',
        'satisfait ou remboursé' => '\bsatisfait(?:e|s|es)?\s+ou\s+rembourse',
        'résultat garanti' => '\b(?:resultats?\s+garantis?|garantie?\s+de\s+resultats?)\b',
        'offre spéciale' => '\b(?:offres?\s+speciales?|prix\s+casses?|remises?\s+exceptionnelles?|offres?\s+promotionnelles?)\b',
    ];

    /**
     * @param list<string>          $extra    expressions of the site's own, matched as they are written (case and accents apart)
     * @param array<string, string> $patterns a regime's own patterns, label => regular expression on the plain text, after the common ones
     *
     * @return list<string> what was found, by its label
     */
    public static function scan(?string $text, array $extra = [], array $patterns = []): array
    {
        $plain = self::plain((string) $text);
        if ('' === $plain) {
            return [];
        }
        $found = [];
        foreach (self::PATTERNS + $patterns as $label => $pattern) {
            if (1 === preg_match('/'.$pattern.'/u', $plain)) {
                $found[] = $label;
            }
        }
        foreach ($extra as $expression) {
            $needle = self::plain((string) $expression);
            if ('' !== $needle && 1 === preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $plain)) {
                $found[] = (string) $expression;
            }
        }

        return array_values(array_unique($found));
    }

    public static function isClean(?string $text, array $extra = [], array $patterns = []): bool
    {
        return [] === self::scan($text, $extra, $patterns);
    }

    /** Lowered, without accents nor tags, typographic apostrophes and spaces made plain. */
    public static function plain(string $text): string
    {
        $text = mb_strtolower(strip_tags($text));
        $text = strtr($text, ['’' => "'", '‘' => "'", "\u{00A0}" => ' ', "\u{202F}" => ' ', 'œ' => 'oe', 'æ' => 'ae']);
        $text = \Normalizer::normalize($text, \Normalizer::FORM_D) ?: $text;
        $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
