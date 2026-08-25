<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\ChecklistItem;

/**
 * Couvre Assetsign::setChecklistValues()/getChecklistResults() (cf. ROADMAP.md V1,
 * issue #74, docs/design/ADR-passeport-v1.md) : formulaire de checklist qualite
 * pose directement sur la fiche Assetsign (meme garde isStillEditable() que
 * addAccessory()/updateObservations()), applicable quel que soit le mode de
 * creation (automatique ou manuel via createManual()).
 */
class AssetsignChecklistTest extends AssetsignTestCase
{
    private function createChecklistItem(int $type, array $movementTypes, string $options = ''): ChecklistItem
    {
        $item = new ChecklistItem();
        $id = (int) $item->add([
            'entities_id'    => 0,
            'name'           => 'PHPUnit Checklist ' . uniqid(),
            'type'           => $type,
            'options'        => $options,
            'is_active'      => 1,
            'movement_types' => $movementTypes,
        ]);
        $item->getFromDB($id);
        return $item;
    }

    public function testSetChecklistValuesStoresCheckboxTextAndSelect(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignChecklist Store');
        $checkbox = $this->createChecklistItem(ChecklistItem::TYPE_CHECKBOX, [Assetsign::TYPE_HANDOVER]);
        $text = $this->createChecklistItem(ChecklistItem::TYPE_TEXT, [Assetsign::TYPE_HANDOVER]);
        $select = $this->createChecklistItem(ChecklistItem::TYPE_SELECT, [Assetsign::TYPE_HANDOVER], "Bon\nMoyen");

        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $assetsign->setChecklistValues([
            $checkbox->getID() => '1',
            $text->getID()     => 'RAS, tout est propre',
            $select->getID()   => 'Bon',
        ]);

        $results = $assetsign->getChecklistResults();
        $this->assertCount(3, $results);

        $byId = [];
        foreach ($results as $result) {
            $byId[$result['id']] = $result;
        }
        $this->assertNull($byId[$checkbox->getID()]['value'], 'Une case a cocher ne stocke aucune valeur, seule sa presence compte.');
        $this->assertSame('RAS, tout est propre', $byId[$text->getID()]['value']);
        $this->assertSame('Bon', $byId[$select->getID()]['value']);
    }

    public function testSetChecklistValuesIgnoresItemsNotApplicableToThisMovementType(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignChecklist NotApplicable');
        $returnOnly = $this->createChecklistItem(ChecklistItem::TYPE_CHECKBOX, [Assetsign::TYPE_RETURN]);

        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        // Formulaire trafique/rejoue : soumet une valeur pour un point qui ne
        // s'applique QU'a la Restitution, sur une fiche d'Attribution.
        $assetsign->setChecklistValues([$returnOnly->getID() => '1']);

        $this->assertCount(0, $assetsign->getChecklistResults(), 'Un point non applicable au type de CETTE fiche ne doit jamais etre enregistre.');
    }

    public function testSetChecklistValuesSkipsEmptyTextAndSelect(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignChecklist EmptyValues');
        $text = $this->createChecklistItem(ChecklistItem::TYPE_TEXT, [Assetsign::TYPE_HANDOVER]);

        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);
        $assetsign->setChecklistValues([$text->getID() => '   ']);

        $this->assertCount(0, $assetsign->getChecklistResults(), 'Un champ texte/menu vide (apres trim) ne doit jamais etre enregistre comme "renseigne".');
    }

    public function testSetChecklistValuesUpsertsRatherThanDuplicates(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignChecklist Upsert');
        $text = $this->createChecklistItem(ChecklistItem::TYPE_TEXT, [Assetsign::TYPE_HANDOVER]);

        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SENT);

        $assetsign->setChecklistValues([$text->getID() => 'Premiere valeur']);
        $assetsign->setChecklistValues([$text->getID() => 'Valeur corrigee']);

        $results = $assetsign->getChecklistResults();
        $this->assertCount(1, $results, 'Un second enregistrement pour le meme point doit remplacer, jamais dupliquer.');
        $this->assertSame('Valeur corrigee', $results[0]['value']);
    }

    public function testSetChecklistValuesNoOpWhenAssetsignNoLongerEditable(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit AssetsignChecklist NotEditable');
        $checkbox = $this->createChecklistItem(ChecklistItem::TYPE_CHECKBOX, [Assetsign::TYPE_HANDOVER]);

        // STATUS_SIGNED : hors de STATUSES_STILL_EDITABLE, meme garde que
        // addAccessory()/updateObservations() deja couvertes ailleurs.
        $assetsign = $this->createBareAssetsign($entityId, Assetsign::TYPE_HANDOVER, Assetsign::STATUS_SIGNED);

        $assetsign->setChecklistValues([$checkbox->getID() => '1']);

        $this->assertCount(0, $assetsign->getChecklistResults(), 'Une fiche signee est une preuve figee : la checklist ne doit plus pouvoir etre modifiee.');
    }
}
