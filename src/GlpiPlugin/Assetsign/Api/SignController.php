<?php

namespace GlpiPlugin\Assetsign\Api;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Pdf\SignatureImageValidator;
use GlpiPlugin\Assetsign\Pdf\SignatureStamper;
use GlpiPlugin\Assetsign\Token;
use RuntimeException;
use Session;

/**
 * Logique partagee par front/sign.php.
 *
 * Connexion GLPI obligatoire (cf. Firewall::STRATEGY_AUTHENTICATED dans setup.php) :
 * un simple lien avec jeton valide ne suffit plus a lui seul. En plus de la validite
 * du jeton (Token::validate()), on verifie ici que l'utilisateur EFFECTIVEMENT
 * connecte est bien le beneficiaire de la remise — sans quoi un autre utilisateur
 * authentifie qui mettrait la main sur le lien (transfert d'e-mail, etc.) pourrait
 * sinon consulter ou signer un document qui ne le concerne pas.
 */
final class SignController
{
    /**
     * Valide le jeton, charge la remise et verifie que l'utilisateur connecte
     * en est bien le beneficiaire. Reutilise par show()/submit() et par
     * le flux de telechargement du PDF (front/sign.php, action=pdf).
     *
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas le beneficiaire
     */
   public function loadAuthorizedAssetsign(string $rawToken): Assetsign {
       $token = Token::validate($rawToken);

       $assetsign = new Assetsign();
      if (!$assetsign->getFromDB((int) $token->fields['plugin_assetsign_assetsigns_id'])) {
          throw new \RuntimeException(__('Attribution introuvable.', 'assetsign'));
      }

       $this->assertCurrentUserIsBeneficiary($assetsign);

       return $assetsign;
   }

    /**
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas le beneficiaire
     */
   public function show(string $rawToken): array {
       $token = Token::validate($rawToken);

       $assetsign = new Assetsign();
      if (!$assetsign->getFromDB((int) $token->fields['plugin_assetsign_assetsigns_id'])) {
          throw new \RuntimeException(__('Attribution introuvable.', 'assetsign'));
      }

       $this->assertCurrentUserIsBeneficiary($assetsign);

      if ((int) $assetsign->fields['status'] === Assetsign::STATUS_SENT) {
          $assetsign->markViewed();
      }

       return [
           'assetsign' => $assetsign,
           'user'   => $assetsign->getBeneficiary(),
           'item'   => $assetsign->getTargetItem(),
           'expiry' => $token->fields['date_expiration'],
       ];
   }

    /**
     * @param string $signatureImagePng Image PNG encodee en base64 (data URI complet)
     * @throws RuntimeException
     */
   public function submit(string $rawToken, string $signatureImagePng, array $meta): void {
       $token = Token::validate($rawToken);

       $assetsign = new Assetsign();
      if (!$assetsign->getFromDB((int) $token->fields['plugin_assetsign_assetsigns_id'])) {
          throw new \RuntimeException(__('Attribution introuvable.', 'assetsign'));
      }

       $this->assertCurrentUserIsBeneficiary($assetsign);

       // Le controle cote client (signature_pad.isEmpty()) ne protege que contre
       // les erreurs d'usage normales ; un appel direct (ou un client modifie)
       // pourrait envoyer n'importe quoi, d'ou cette verification independante.
       SignatureImageValidator::assertValid($signatureImagePng);

       $stamper = new SignatureStamper();
       $result = $stamper->apply($assetsign, $signatureImagePng);

       $user = $assetsign->getBeneficiary();

       $assetsign->markSigned($result['path'], [
           'signer_name'   => trim(\formatUserName(0, $user['name'] ?? '', $user['realname'] ?? '', $user['firstname'] ?? '')),
           'signer_email'  => $user['email'] ?? '',
           'ip_address'    => $meta['ip'] ?? '',
           'user_agent'    => $meta['user_agent'] ?? '',
           'document_hash' => $result['hash'],
           'signed_at'     => $result['signed_at'],
       ]);

       $token->markUsed();
   }

   private function assertCurrentUserIsBeneficiary(Assetsign $assetsign): void {
       $currentUserId = (int) Session::getLoginUserID();

      if ($currentUserId <= 0) {
          // Ne devrait pas arriver (Firewall::STRATEGY_AUTHENTICATED impose deja
          // une session) ; filet de securite si jamais l'appel se fait autrement.
          throw new \RuntimeException(__('Vous devez être connecté pour accéder à ce document.', 'assetsign'));
      }

      if ($currentUserId !== (int) $assetsign->fields['users_id']) {
          throw new \RuntimeException(__('Ce document ne correspond pas à votre compte utilisateur.', 'assetsign'));
      }
   }
}
