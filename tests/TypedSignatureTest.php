<?php

namespace GlpiPlugin\Assetsign\Tests;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Signature;

/**
 * Issue #275 : la méthode de signature (tracé ou nom tapé) est conservée dans la preuve, et
 * l'option est active par défaut.
 */
class TypedSignatureTest extends AssetsignTestCase
{
    public function testProofKeepsTheSignatureMethod(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Signature tapee');
        $computer = $this->createTestComputer($entityId, 'PHPUnit PC Signature tapee');
        $assetsign = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_DON, 2);

        Signature::recordProofForAssetsign($assetsign, ['signer_name' => 'Camille Durand', 'signature_method' => 'typed']);
        $this->assertSame('typed', Signature::getForAssetsign($assetsign->getID())['signature_method']);

        $other = Assetsign::createManual('Computer', $computer->getID(), Assetsign::TYPE_VENTE, 2);
        Signature::recordProofForAssetsign($other, ['signer_name' => 'Camille Durand', 'signature_method' => 'autre']);
        $this->assertSame('drawn', Signature::getForAssetsign($other->getID())['signature_method'], 'Valeur inconnue : tracé.');
    }

    public function testTypedSignatureIsAllowedByDefault(): void
    {
        $entityId = $this->createTestEntity(0, 'PHPUnit Signature tapee defaut');
        $this->assertSame(1, (int) Config::getForEntity($entityId)->fields['allow_typed_signature']);
    }
}
