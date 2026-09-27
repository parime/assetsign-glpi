<?php

namespace GlpiPlugin\Assetsign\Api;

use GlpiPlugin\Assetsign\Assetsign;
use GlpiPlugin\Assetsign\Config;
use GlpiPlugin\Assetsign\Pdf\SignatureImageValidator;
use GlpiPlugin\Assetsign\Pdf\SignatureStamper;
use GlpiPlugin\Assetsign\Signature;
use GlpiPlugin\Assetsign\Token;
use NotificationEvent;
use RuntimeException;
use Session;

/**
 * Logique partagee par front/sign.php.
 *
 * Connexion GLPI obligatoire (cf. Firewall::STRATEGY_AUTHENTICATED dans setup.php) :
 * un simple lien avec jeton valide ne suffit plus a lui seul. En plus de la validite
 * du jeton (Token::validate()), on verifie ici que l'utilisateur EFFECTIVEMENT
 * connecte est bien AUTORISE a signer (le beneficiaire de la remise, OU son
 * delegue le cas echeant, cf. Assetsign::delegateSignatureTo(), issue #115 —
 * OU le responsable hierarchique contre-signataire, cf. Assetsign::
 * launchWorkflow(), issue #143) — sans quoi un autre utilisateur authentifie
 * qui mettrait la main sur le lien (transfert d'e-mail, etc.) pourrait sinon
 * consulter ou signer un document qui ne le concerne pas.
 */
final class SignController
{
    /**
     * Valide le jeton, charge la remise et verifie que l'utilisateur connecte
     * est bien autorise a y acceder — factorise l'enchainement commun a
     * toutes les methodes publiques ci-dessous (chacune a ensuite besoin, en
     * plus de la fiche, du role porte par le jeton (cf. Token::$for_cosigner,
     * issue #143) pour brancher son propre comportement).
     *
     * @return array{0: Assetsign, 1: Token}
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas autorise
     */
   private function loadAndAuthorize(string $rawToken): array {
       $token = Token::validate($rawToken);

       $assetsign = new Assetsign();
      if (!$assetsign->getFromDB((int) $token->fields['plugin_assetsign_assetsigns_id'])) {
          throw new \RuntimeException(__('Attribution introuvable.', 'assetsign'));
      }

       $this->assertCurrentUserIsAuthorizedSigner($assetsign, $token);
       // Apres, pas avant (cf. Token::recordAttempt()) : ne compte que les
       // acces reellement autorises dans la limite anti-abus du jeton.
       $token->recordAttempt();

       return [$assetsign, $token];
   }

    /**
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas autorise
     */
   public function loadAuthorizedAssetsign(string $rawToken): Assetsign {
       [$assetsign] = $this->loadAndAuthorize($rawToken);
       return $assetsign;
   }

    /**
     * Identifiant du Document a servir pour le telechargement PDF
     * (front/sign.php, action=pdf) : le PDF non signe pour un jeton
     * beneficiaire (comme avant), ou le PDF deja signe par le beneficiaire
     * (document_id_signed) pour un jeton responsable (issue #143) — c'est CE
     * document que le responsable doit consulter avant de le
     * contre-signer, jamais la version vierge.
     *
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas autorise
     */
   public function getPdfDocumentId(string $rawToken): int {
       [$assetsign, $token] = $this->loadAndAuthorize($rawToken);

       return $token->fields['for_cosigner']
           ? (int) $assetsign->fields['document_id_signed']
           : (int) $assetsign->fields['document_id_unsigned'];
   }

    /**
     * @throws RuntimeException si le jeton est invalide/expire/deja utilise,
     *                          ou si l'utilisateur connecte n'est pas autorise
     */
   public function show(string $rawToken): array {
       [$assetsign, $token] = $this->loadAndAuthorize($rawToken);

       $isCosigner = (bool) $token->fields['for_cosigner'];

      if (!$isCosigner && (int) $assetsign->fields['status'] === Assetsign::STATUS_SENT) {
          $assetsign->markViewed();
      } else if ($isCosigner) {
          // Contre-signature (issue #143) : markViewed() horodate
          // date_viewed sans transition de statut dans ce cas (cf. son
          // docblock) — appele inconditionnellement, il ne fait rien si la
          // fiche n'est plus (ou pas encore) STATUS_AWAITING_COSIGNATURE.
          $assetsign->markViewed();
      }

       return [
           'assetsign' => $assetsign,
           // Toujours le beneficiaire D'ORIGINE (jamais le delegue, jamais le
           // responsable) : c'est lui qui reste nomme sur le document et
           // l'en-tete de la page, meme quand c'est en realite le delegue ou
           // le responsable qui est connecte (cf. is_delegate_signer/
           // is_cosigner ci-dessous pour distinguer cote gabarit).
           'user'   => $assetsign->getBeneficiary(),
           'item'   => $assetsign->getTargetItem(),
           'expiry' => $token->fields['date_expiration'],
           // Cf. Assetsign::delegateSignatureTo() (issue #115) : distingue,
           // cote page de signature, le beneficiaire d'origine connecte du
           // delegue connecte (bandeau d'information + formulaire
           // d'auto-delegation reserve au beneficiaire d'origine, cf.
           // front/sign.php).
           'is_delegate_signer' => !$isCosigner
               && (int) ($assetsign->fields['delegated_users_id'] ?? 0) > 0
               && (int) ($assetsign->fields['delegated_users_id'] ?? 0) === (int) Session::getLoginUserID(),
           // Signatures multiples (issue #143) : distingue, cote page de
           // signature, le responsable hierarchique connecte pour
           // contre-signer (bandeau d'information dedie, cf.
           // front/sign.php/sign_page.html.twig) — determine par le ROLE DU
           // JETON, pas par une comparaison d'identite (cf. le docblock de
           // assertCurrentUserIsAuthorizedSigner() ci-dessous).
           'is_cosigner' => $isCosigner,
           'cosigner'    => $assetsign->getCosigner(),
       ];
   }

    /**
     * @param string $signatureImagePng Image PNG encodee en base64 (data URI complet)
     * @throws RuntimeException
     */
    /**
     * @return string|null Le jeton brut du responsable (issue #143), si cette
     *     soumission vient de faire passer la fiche en attente de
     *     contre-signature — jamais utilise par front/sign.php (qui n'en a
     *     pas besoin, cf. NotificationEvent ci-dessous), seulement expose
     *     pour permettre de tester le flux complet de bout en bout sans
     *     dependre d'un e-mail reellement envoye.
     */
   public function submit(string $rawToken, string $signatureImagePng, array $meta): ?string {
       [$assetsign, $token] = $this->loadAndAuthorize($rawToken);

       // Le controle cote client (signature_pad.isEmpty()) ne protege que contre
       // les erreurs d'usage normales ; un appel direct (ou un client modifie)
       // pourrait envoyer n'importe quoi, d'ou cette verification independante.
       SignatureImageValidator::assertValid($signatureImagePng);

       $newCosignerToken = null;
      if ($token->fields['for_cosigner']) {
          $this->submitCosignature($assetsign, $signatureImagePng, $meta);
      } else {
          $newCosignerToken = $this->submitBeneficiarySignature($assetsign, $signatureImagePng, $meta);
      }

       $token->markUsed();

       return $newCosignerToken;
   }

    /**
     * Chemin existant (beneficiaire ou delegue), inchange — sauf a l'issue
     * #143 : si la fiche requiert une contre-signature (cosigner_users_id
     * snapshote par Assetsign::launchWorkflow()), elle passe en attente du
     * responsable (markAwaitingCosignature()) au lieu d'etre consideree
     * signee (markSigned()), et le jeton frais du responsable est retourne.
     */
   private function submitBeneficiarySignature(Assetsign $assetsign, string $signatureImagePng, array $meta): ?string {
       $stamper = new SignatureStamper();
       $result = $stamper->apply($assetsign, $signatureImagePng);

       // getActualSigner() (pas getBeneficiary()) : reflete QUI a reellement
       // signe (delegue ou beneficiaire d'origine), cf. son docblock — sans
       // quoi la preuve de signature mentirait des qu'un delegue signe.
       $signer = $assetsign->getActualSigner();
       $proof = [
           'signer_name'   => trim(\formatUserName(0, $signer['name'] ?? '', $signer['realname'] ?? '', $signer['firstname'] ?? '')),
           'signer_email'  => $signer['email'] ?? '',
           'ip_address'    => $meta['ip'] ?? '',
           'user_agent'    => $meta['user_agent'] ?? '',
           'document_hash' => $result['hash'],
           'signed_at'     => $result['signed_at'],
       ];

       $cosignerId = (int) ($assetsign->fields['cosigner_users_id'] ?? 0);
       if ($cosignerId > 0) {
          $assetsign->markAwaitingCosignature($result['path'], $proof, $signatureImagePng);

          // Nouveau jeton, distinct de celui du beneficiaire (deja invalide
          // par markAwaitingCosignature()), pour le responsable — cf.
          // Token::createForAssetsign(), issue #143.
          $config = Config::getForEntity((int) $assetsign->fields['entities_id']);
          $raw = Token::createForAssetsign($assetsign, (int) $config->fields['link_validity_days'], forCosigner: true);
          $assetsign->_current_raw_token = $raw;

          // Notifie ICI (pas dans markAwaitingCosignature()) : le jeton du
          // responsable, cree juste au-dessus, doit deja exister pour que
          // ##assetsign.sign_url## se resolve correctement (cf. le docblock
          // de markAwaitingCosignature()).
          NotificationEvent::raiseEvent('cosignature_requested', $assetsign);

          return $raw;
       }

       $assetsign->markSigned($result['path'], $proof);
       return null;
   }

    /**
     * Contre-signature (issue #143) : re-rend le PDF avec DEUX signatures —
     * celle du beneficiaire, reconstituee depuis ce qui a ete enregistre a
     * l'etape precedente (cosigner_pending_signature + le proof deja
     * stocke), et celle du responsable, tout juste soumise. Distinct de
     * submitBeneficiarySignature() ci-dessus : SignatureStamper::apply()
     * (via getActualSigner()) ne convient pas ici, la session courante
     * etant TOUJOURS celle du responsable a ce stade, jamais celle du
     * beneficiaire (cf. le docblock de SignatureStamper::applyCosignature()).
     */
   private function submitCosignature(Assetsign $assetsign, string $signatureImagePng, array $meta): void {
       $beneficiaryPendingSignature = (string) ($assetsign->fields['cosigner_pending_signature'] ?? '');
      if ($beneficiaryPendingSignature === '') {
          throw new \RuntimeException(__('La signature du bénéficiaire est introuvable pour cette fiche.', 'assetsign'));
      }

       $beneficiarySignerIdentity = $assetsign->getBeneficiary();
       $beneficiaryProof = Signature::getForAssetsign($assetsign->getID());

       $beneficiarySigner = [
           'signature_image' => $beneficiaryPendingSignature,
           'signed_at'       => $beneficiaryProof['signed_at'] ?? $assetsign->fields['date_mod'],
           'signer_name'     => $beneficiaryProof['signer_name'] ?? trim(\formatUserName(0, $beneficiarySignerIdentity['name'] ?? '', $beneficiarySignerIdentity['realname'] ?? '', $beneficiarySignerIdentity['firstname'] ?? '')),
           'signer_email'    => $beneficiaryProof['signer_email'] ?? ($beneficiarySignerIdentity['email'] ?? ''),
       ];

       $cosignerIdentity = $assetsign->getCosigner() ?? [];
       $signedAt = date('Y-m-d H:i:s');
       $cosignerSigner = [
           'signature_image' => $signatureImagePng,
           'signed_at'       => $signedAt,
           'signer_name'     => trim(\formatUserName(0, $cosignerIdentity['name'] ?? '', $cosignerIdentity['realname'] ?? '', $cosignerIdentity['firstname'] ?? '')),
           'signer_email'    => $cosignerIdentity['email'] ?? '',
       ];

       $stamper = new SignatureStamper();
       $result = $stamper->applyCosignature($assetsign, $beneficiarySigner, $cosignerSigner);

       $assetsign->markCosigned($result['path'], [
           'signer_name'   => $cosignerSigner['signer_name'],
           'signer_email'  => $cosignerSigner['signer_email'],
           'ip_address'    => $meta['ip'] ?? '',
           'user_agent'    => $meta['user_agent'] ?? '',
           'document_hash' => $result['hash'],
           'signed_at'     => $signedAt,
       ]);
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
       [$assetsign, $token] = $this->loadAndAuthorize($rawToken);

      // Signatures multiples (issue #143) : pas de delegation pour le role de
      // contre-signataire en V1 (extension possible plus tard, hors perimetre
      // ici) — un responsable hierarchique ne peut pas deleguer sa propre
      // contre-signature depuis cette page.
      if ($token->fields['for_cosigner']) {
          throw new \RuntimeException(__('La délégation n\'est pas disponible pour le rôle de contre-signataire.', 'assetsign'));
      }

      // Seul le beneficiaire D'ORIGINE peut s'auto-deleguer depuis cette page
      // (cf. commentaire de front/sign.php) : assertCurrentUserIsAuthorizedSigner()
      // accepte par conception le beneficiaire OU le delegue courant, ce qui
      // sans ce garde-fou permettrait a un delegue de re-deleguer en chaine
      // (B -> C -> D...) sans que le beneficiaire d'origine n'en soit jamais
      // informe - seul un admin/technicien a vocation a re-deleguer.
       $currentDelegateId = (int) ($assetsign->fields['delegated_users_id'] ?? 0);
      if ($currentDelegateId > 0 && $currentDelegateId === (int) Session::getLoginUserID()) {
          throw new \RuntimeException(__('Seul le bénéficiaire d\'origine peut déléguer la signature depuis cette page.', 'assetsign'));
      }

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
     * Assetsign::delegateSignatureTo() (issue #115) — OU, si le jeton porte
     * le role responsable (Token::$for_cosigner, issue #143), le compte
     * contre-signataire (cosigner_users_id).
     *
     * C'est le ROLE DU JETON (pas seulement l'identite de la session) qui
     * determine quelle comparaison appliquer : un jeton "beneficiaire" ne
     * peut jamais autoriser via cosigner_users_id, et reciproquement — sans
     * cette distinction, un beneficiaire qui serait par ailleurs responsable
     * hierarchique d'un AUTRE dossier pourrait par erreur s'auto-autoriser
     * sur cette fiche avec le mauvais jeton.
     */
   private function assertCurrentUserIsAuthorizedSigner(Assetsign $assetsign, Token $token): void {
       $currentUserId = (int) Session::getLoginUserID();

      if ($currentUserId <= 0) {
          // Ne devrait pas arriver (Firewall::STRATEGY_AUTHENTICATED impose deja
          // une session) ; filet de securite si jamais l'appel se fait autrement.
          throw new \RuntimeException(__('Vous devez être connecté pour accéder à ce document.', 'assetsign'));
      }

      if ($token->fields['for_cosigner']) {
         if ($currentUserId !== (int) ($assetsign->fields['cosigner_users_id'] ?? 0)) {
             throw new \RuntimeException(__('Ce document ne correspond pas à votre compte utilisateur.', 'assetsign'));
         }
          return;
      }

       $delegateId = (int) ($assetsign->fields['delegated_users_id'] ?? 0);
      if ($currentUserId !== (int) $assetsign->fields['users_id'] && !($delegateId > 0 && $currentUserId === $delegateId)) {
          throw new \RuntimeException(__('Ce document ne correspond pas à votre compte utilisateur.', 'assetsign'));
      }
   }
}
