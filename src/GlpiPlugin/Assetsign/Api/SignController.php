<?php

namespace GlpiPlugin\Assetsign\Api;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
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
 * connecte est bien AUTORISE a signer (le beneficiaire de la remise, OU son
 * delegue le cas echeant, cf. Assetsign::delegateSignatureTo(), issue #115) —
 * sans quoi un autre utilisateur authentifie qui mettrait la main sur le lien
 * (transfert d'e-mail, etc.) pourrait sinon consulter ou signer un document
 * qui ne le concerne pas.
 */
final class SignController
{
    /**
     * Valide le jeton, charge la remise et verifie que l'utilisateur connecte
     * est bien autorise a la signer (beneficiaire ou delegue). Reutilise par
     * show()/submit() et par le flux de telechargement du PDF
     * (front/sign.php, action=pdf).
     *
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas autorise
     */
   public function loadAuthorizedAssetsign(string $rawToken): Assetsign {
       $token = Token::validate($rawToken);

       $assetsign = new Assetsign();
      if (!$assetsign->getFromDB((int) $token->fields['plugin_assetsign_assetsigns_id'])) {
          throw new \RuntimeException(__('Attribution introuvable.', 'assetsign'));
      }

       $this->assertCurrentUserIsAuthorizedSigner($assetsign);

       return $assetsign;
   }

    /**
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas autorise
     */
   public function show(string $rawToken): array {
       $token = Token::validate($rawToken);

       $assetsign = new Assetsign();
      if (!$assetsign->getFromDB((int) $token->fields['plugin_assetsign_assetsigns_id'])) {
          throw new \RuntimeException(__('Attribution introuvable.', 'assetsign'));
      }

       $this->assertCurrentUserIsAuthorizedSigner($assetsign);

      if ((int) $assetsign->fields['status'] === Assetsign::STATUS_SENT) {
          $assetsign->markViewed();
      }

       return [
           'assetsign' => $assetsign,
           // Toujours le beneficiaire D'ORIGINE (jamais le delegue) : c'est
           // lui qui reste nomme sur le document et l'en-tete de la page,
           // meme quand c'est en realite le delegue qui est connecte (cf.
           // is_delegated_signer / delegate ci-dessous pour distinguer les
           // deux cote gabarit).
           'user'   => $assetsign->getBeneficiary(),
           'item'   => $assetsign->getTargetItem(),
           'expiry' => $token->fields['date_expiration'],
           // Cf. Assetsign::delegateSignatureTo() (issue #115) : distingue,
           // cote page de signature, le beneficiaire d'origine connecte du
           // delegue connecte (bandeau d'information + formulaire
           // d'auto-delegation reserve au beneficiaire d'origine, cf.
           // front/sign.php).
           'is_delegate_signer' => (int) ($assetsign->fields['delegated_users_id'] ?? 0) > 0
               && (int) ($assetsign->fields['delegated_users_id'] ?? 0) === (int) Session::getLoginUserID(),
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

       $this->assertCurrentUserIsAuthorizedSigner($assetsign);

       // Le controle cote client (signature_pad.isEmpty()) ne protege que contre
       // les erreurs d'usage normales ; un appel direct (ou un client modifie)
       // pourrait envoyer n'importe quoi, d'ou cette verification independante.
       SignatureImageValidator::assertValid($signatureImagePng);

       $stamper = new SignatureStamper();
       $result = $stamper->apply($assetsign, $signatureImagePng);

       // getActualSigner() (pas getBeneficiary()) : reflete QUI a reellement
       // signe (delegue ou beneficiaire d'origine), cf. son docblock — sans
       // quoi la preuve de signature mentirait des qu'un delegue signe.
       $signer = $assetsign->getActualSigner();

       $assetsign->markSigned($result['path'], [
           'signer_name'   => trim(\formatUserName(0, $signer['name'] ?? '', $signer['realname'] ?? '', $signer['firstname'] ?? '')),
           'signer_email'  => $signer['email'] ?? '',
           'ip_address'    => $meta['ip'] ?? '',
           'user_agent'    => $meta['user_agent'] ?? '',
           'document_hash' => $result['hash'],
           'signed_at'     => $result['signed_at'],
       ]);

       $token->markUsed();
   }

    /**
     * Auto-delegation par le beneficiaire (ou le delegue actuel) lui-meme,
     * depuis la page de signature (front/sign.php) — cf.
     * Config::enable_self_service_delegation (issue #115). Reserve aux comptes
     * GLPI existants (Assetsign::delegateSignatureTo() rejette tout le reste),
     * jamais un beneficiaire externe/texte libre.
     *
     * @throws RuntimeException si le jeton est invalide, l'utilisateur non
     *                          autorise, le reglage desactive pour l'entite,
     *                          ou si Assetsign::delegateSignatureTo() rejette
     *                          la demande (fiche non modifiable, compte
     *                          delegue introuvable/identique...).
     */
   public function delegateSelfService(string $rawToken, int $delegateUsersId, string $reason): void {
       $token = Token::validate($rawToken);

       $assetsign = new Assetsign();
      if (!$assetsign->getFromDB((int) $token->fields['plugin_assetsign_assetsigns_id'])) {
          throw new \RuntimeException(__('Attribution introuvable.', 'assetsign'));
      }

       $this->assertCurrentUserIsAuthorizedSigner($assetsign);

       $config = Config::getForEntity((int) $assetsign->fields['entities_id']);
      if (!$config->fields['enable_self_service_delegation']) {
          throw new \RuntimeException(__('La délégation n\'est pas activée pour cette entité.', 'assetsign'));
      }
      if (trim($reason) === '') {
          // Motif obligatoire en self-service (contrairement au flux
          // technicien/admin, ou il reste facultatif) : cf. spec issue #115.
          throw new \RuntimeException(__('Le motif de la délégation est obligatoire.', 'assetsign'));
      }

       $assetsign->delegateSignatureTo($delegateUsersId, $reason, (int) Session::getLoginUserID());
   }

    /**
     * Accepte le beneficiaire d'origine (users_id) OU, si une delegation est
     * active, le compte delegue (delegated_users_id) — cf.
     * Assetsign::delegateSignatureTo() (issue #115). Renommee depuis
     * assertCurrentUserIsBeneficiary() : conserve le meme role, elargi au cas
     * delegue.
     */
   private function assertCurrentUserIsAuthorizedSigner(Assetsign $assetsign): void {
       $currentUserId = (int) Session::getLoginUserID();

      if ($currentUserId <= 0) {
          // Ne devrait pas arriver (Firewall::STRATEGY_AUTHENTICATED impose deja
          // une session) ; filet de securite si jamais l'appel se fait autrement.
          throw new \RuntimeException(__('Vous devez être connecté pour accéder à ce document.', 'assetsign'));
      }

       $delegateId = (int) ($assetsign->fields['delegated_users_id'] ?? 0);
      if ($currentUserId !== (int) $assetsign->fields['users_id'] && !($delegateId > 0 && $currentUserId === $delegateId)) {
          throw new \RuntimeException(__('Ce document ne correspond pas à votre compte utilisateur.', 'assetsign'));
      }
   }
}
