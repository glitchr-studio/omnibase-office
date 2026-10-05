<?php

namespace Base\Office\DependencyInjection;

/**
 * How the practice is named in the bundle's texts: "le cabinet" for a law
 * firm, "l'étude" or "l'office" for a notary's, "la maison de santé"...
 * office.vocabulary.practice names a preset or gives the forms; they reach
 * every translation as global parameters (framework.translator.globals):
 *
 *     {practice}          cabinet            étude              maison de santé
 *     {the_practice}      le cabinet         l’étude            la maison de santé
 *     {The_practice}      Le cabinet         L’étude            La maison de santé
 *     {at_the_practice}   au cabinet         à l’étude          à la maison de santé
 *     {At_the_practice}   Au cabinet         À l’étude          À la maison de santé
 *     {of_the_practice}   du cabinet         de l’étude         de la maison de santé
 */
final class Vocabulary
{
    public const PRESETS = [
        'cabinet' => ['name' => 'cabinet', 'the' => 'le cabinet', 'at' => 'au cabinet', 'of' => 'du cabinet'],
        'etude' => ['name' => 'étude', 'the' => 'l’étude', 'at' => 'à l’étude', 'of' => 'de l’étude'],
        'office' => ['name' => 'office', 'the' => 'l’office', 'at' => 'à l’office', 'of' => 'de l’office'],
        'maison_de_sante' => ['name' => 'maison de santé', 'the' => 'la maison de santé', 'at' => 'à la maison de santé', 'of' => 'de la maison de santé'],
    ];

    /**
     * @param string|array{name?: string, the?: string, at?: string, of?: string} $practice a preset's name, or the forms (what is missing is built from the name)
     *
     * @return array<string, string> the global translation parameters
     */
    public static function globals(string|array $practice = 'cabinet'): array
    {
        if (\is_string($practice)) {
            $forms = self::PRESETS[$practice] ?? throw new \InvalidArgumentException(\sprintf('office.vocabulary.practice: "%s" is not one of %s; give its forms instead ({name, the, at, of}).', $practice, implode(', ', array_keys(self::PRESETS))));
        } else {
            $name = (string) ($practice['name'] ?? self::PRESETS['cabinet']['name']);
            $forms = $practice + ['name' => $name, 'the' => 'le '.$name, 'at' => 'au '.$name, 'of' => 'du '.$name];
        }

        return [
            '{practice}' => $forms['name'],
            '{the_practice}' => $forms['the'],
            '{The_practice}' => self::capital($forms['the']),
            '{at_the_practice}' => $forms['at'],
            '{At_the_practice}' => self::capital($forms['at']),
            '{of_the_practice}' => $forms['of'],
        ];
    }

    private static function capital(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
