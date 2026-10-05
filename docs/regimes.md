---
title: What a regime builds on
order: 50
---

# What a regime builds on

A regime - `omnibase/notary`, `omnibase/lawyer`, `omnibase/health` - is the
profession's own rules on top of omnibase/office. What two regimes need is
written here once; a regime keeps what is its alone (its texts, its
compliance checks, its table names).

## Fields of practice: `Area`

`Base\Office\Entity\Area` is a mapped superclass: the name, the slug, a
summary, the page's text, whether it is shown and where, who of the team
follows it, and the methods around them (`getParagraphs()`,
`getPublicText()` for the wording check, `getItemsText()` / `setItemsText()`
for a form that edits the list one a line). A regime's entity extends it,
names its table and maps the two things whose names are its own - the list
of what is done in the field, and the join table of the members:

```php
namespace Base\Notary\Entity;

#[ORM\Entity(repositoryClass: AreaRepository::class)]
#[ORM\Table(name: 'notary_area')]
class Area extends \Base\Office\Entity\Area
{
    #[ORM\Column(type: 'json')]
    protected array $deeds = [];                        // a lawyer's: $matters

    #[ORM\ManyToMany(targetEntity: Member::class)]
    #[ORM\JoinTable(name: 'notary_area_member')]
    protected Collection $members;

    public function getItems(): array { return $this->deeds; }
    public function setItems(?array $items): static { $this->deeds = self::lines($items); return $this; }
}
```

Its repository extends `Base\Office\Repository\AreaRepository`
(`findActive()`, `findOneActiveBySlug()`, `findForMember()`) and names the
entity in its constructor.

`Base\Notary\Entity\Area` and `Base\Lawyer\Entity\Area` were two copies of
that class; they extend it since 2026-10-05 with the same tables, columns and
methods (`getDeeds()`, `getMatters()`...): no migration, nothing to change in
an application.

## The wording guard: `Wording`

`Base\Office\Guard\Wording::scan($text, $extra, $patterns)` finds what the
communication of a regulated practice must not carry - comparison with
colleagues, disparagement, advertising's superlatives ("le meilleur",
"numéro 1", "moins cher", "nos concurrents", "résultat garanti"...) - on the
text lowered and stripped of its accents and tags (`Wording::plain()`).

- `$extra`: expressions of the site's own, matched as they are written;
- `$patterns`: a regime's own, `label => regular expression`, after the
  common ones. The lawyers add the mention of former judicial functions.

A regime keeps its own `Wording` (`Base\Notary\Guard\Wording`,
`Base\Lawyer\Guard\Wording`: the same `scan()`, `isClean()`, `plain()` as
before) citing its texts, and delegates here. A list of expressions helps the
person who writes; it judges nothing.
