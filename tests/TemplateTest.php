<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Template;

/**
 * Verifie le repli du gabarit par defaut : une entite sans gabarit propre pour
 * un type de remise donne doit heriter du gabarit racine (seme a l'installation
 * du plugin), et aucun gabarit inexistant ne doit jamais faire planter l'appelant.
 */
class TemplateTest extends AssetsignTestCase
{
    public function testFallsBackToRootTemplateWhenEntityHasNone(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Entity Sans Gabarit');

        $template = Template::getDefaultFor(Assetsign::TYPE_HANDOVER, $entityId);

        $this->assertNotNull($template, "Une entite sans gabarit propre doit heriter du gabarit par defaut de l'entite racine.");
        $this->assertSame(0, (int) $template->fields['entities_id']);
        $this->assertSame(1, (int) $template->fields['is_default']);
    }

    public function testReturnsNullWhenNoDefaultTemplateExistsForType(): void
    {
        // Type bidon qui ne correspond a aucun gabarit seme a l'installation.
        $template = Template::getDefaultFor(999, 0);
        $this->assertNull($template);
    }

    public function testEachHandoverTypeHasItsOwnDefaultTemplate(): void
    {
        // Uniquement Assetsign et Restitution : le type Echange a ete retire du plugin
        // (un transfert direct entre deux personnes est desormais une simple assetsign
        // au nouveau detenteur, cf. Assetsign::handleUserBasedTrigger()).
        foreach ([Assetsign::TYPE_HANDOVER, Assetsign::TYPE_RETURN] as $type) {
            $template = Template::getDefaultFor($type, 0);
            $this->assertNotNull($template, "Le type $type doit avoir un gabarit par defaut sur l'entite racine (seme par Template::install()).");
            $this->assertSame($type, (int) $template->fields['type']);
        }
    }
}
