<?php

namespace Base\Office\Tests\Entity;

use Base\Office\Entity\Area;
use Base\Office\Entity\Member;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase;

/** A field of practice as the regimes share it: its slug, its list, its paragraphs, what the wording check reads. */
final class AreaTest extends TestCase
{
    private function area(string $name = 'Droit de la famille'): Area
    {
        return new class($name) extends Area {
            protected array $steps = [];
            protected Collection $members;

            public function getItems(): array { return $this->steps; }
            public function setItems(?array $items): static { $this->steps = self::lines($items); return $this; }
        };
    }

    public function testTheSlugComesFromTheName(): void
    {
        $area = $this->area();

        self::assertSame('droit-de-la-famille', $area->getSlug());
        self::assertSame('Droit de la famille', (string) $area);
        self::assertSame('droit-des-societes', $this->area('')->setName(' Droit des sociétés ')->getSlug(), 'given with the name when it had none');
        self::assertSame('droit-de-la-famille', $area->setName('Famille')->getSlug(), 'kept once set: an address does not move with a title');
    }

    public function testTheListIsEditedOneALine(): void
    {
        $area = $this->area()->setItemsText("Divorce\n\n  Adoption \r\nSuccession");

        self::assertSame(['Divorce', 'Adoption', 'Succession'], $area->getItems());
        self::assertSame("Divorce\nAdoption\nSuccession", $area->getItemsText());
    }

    public function testParagraphsAndPublicText(): void
    {
        $area = $this->area()->setSummary('En deux phrases.')->setBody("Premier.\n\nSecond.")->setItems(['Divorce']);

        self::assertSame(['Premier.', 'Second.'], $area->getParagraphs());
        self::assertSame("Droit de la famille\nEn deux phrases.\nPremier.\n\nSecond.\nDivorce", $area->getPublicText());
        self::assertNull($area->setBody('')->getBody());
    }

    public function testMembers(): void
    {
        $area = $this->area();
        $member = (new \ReflectionClass(Member::class))->newInstanceWithoutConstructor();
        $area->addMember($member)->addMember($member);

        self::assertCount(1, $area->getMembers());
        self::assertCount(0, $area->removeMember($member)->getMembers());
    }
}
