<?php

namespace GlpiPlugin\Assetsign;

/**
 * Documents qui attendent la signature d'un utilisateur donne (issue #150) : alimente le bandeau
 * de la page d'accueil (Hooks::DISPLAY_CENTRAL) et la page "Mes documents a signer"
 * (front/mysignatures.php).
 *
 * Jusqu'ici, le seul moyen d'apprendre qu'un document attendait sa signature etait l'e-mail
 * contenant le lien : un utilisateur sans adresse e-mail n'en etait jamais informe. Ces deux
 * points d'entree ne dependent que de la session GLPI.
 *
 * Memes regles d'autorisation que SignController::assertCurrentUserIsAuthorizedSigner(), qui
 * reste de toute facon le controle final sur la page de signature :
 * - role "signataire" (fiche Envoyee/Consultee) : le beneficiaire, ou son delegue si la
 *   signature a ete deleguee - dans ce cas le document n'apparait plus chez le beneficiaire
 *   d'origine, c'est desormais au delegue d'agir (cf. Assetsign::delegateSignatureTo()) ;
 * - role "contre-signataire" (fiche en attente de contre-signature) : le responsable
 *   hierarchique snapshote dans cosigner_users_id (issue #143).
 */
final class PendingSignatures
{
   public const ROLE_SIGNER   = 'signer';
   public const ROLE_COSIGNER = 'cosigner';

    /** Statuts ou le beneficiaire (ou son delegue) doit encore signer. */
   private const STATUSES_SIGNER = [Assetsign::STATUS_SENT, Assetsign::STATUS_VIEWED];

    /**
     * @return list<array{assetsign: Assetsign, role: string}> Du plus ancien envoi au plus recent :
     *         le document le plus proche de l'expiration en premier.
     */
   public static function forUser(int $usersId): array {
       global $DB;

      if ($usersId <= 0) {
          return [];
      }

       $pending = [];
      foreach ($DB->request([
          'FROM'  => Assetsign::getTable(),
          'WHERE' => self::criteria($usersId),
          'ORDER' => ['date_sent ASC', 'id ASC'],
      ]) as $row) {
          $assetsign = new Assetsign();
          $assetsign->fields = $row;
          $role = self::roleOf($assetsign, $usersId);
         if ($role !== null) {
             $pending[] = ['assetsign' => $assetsign, 'role' => $role];
         }
      }

       return $pending;
   }

   public static function countForUser(int $usersId): int {
      if ($usersId <= 0) {
          return 0;
      }

       return countElementsInTable(Assetsign::getTable(), self::criteria($usersId));
   }

    /**
     * Role attendu de $usersId sur cette fiche a son etape actuelle, ou null si elle n'attend
     * rien de lui (deja signee, pas encore envoyee, signature deleguee a quelqu'un d'autre...).
     */
   public static function roleOf(Assetsign $assetsign, int $usersId): ?string {
       $status = (int) $assetsign->fields['status'];

      if ($usersId <= 0 || !empty($assetsign->fields['is_deleted'])) {
          return null;
      }

      if (in_array($status, self::STATUSES_SIGNER, true)) {
          $delegateId = (int) ($assetsign->fields['delegated_users_id'] ?? 0);
          $expected = $delegateId > 0 ? $delegateId : (int) $assetsign->fields['users_id'];
          return $expected === $usersId ? self::ROLE_SIGNER : null;
      }

      if ($status === Assetsign::STATUS_AWAITING_COSIGNATURE
          && (int) ($assetsign->fields['cosigner_users_id'] ?? 0) === $usersId
      ) {
          return self::ROLE_COSIGNER;
      }

       return null;
   }

    /**
     * Emet un nouveau jeton de signature pour $usersId sur cette fiche et le renvoie (brut), a
     * passer a front/sign.php.
     *
     * Le lien deja envoye par e-mail ne peut pas etre reaffiche (seul son hash est stocke, cf.
     * Token) : on en cree donc un nouveau. createForAssetsign() et non regenerateForAssetsign() :
     * le lien recu par e-mail, s'il y en a un, reste valable. Validite = le temps qu'il reste
     * avant l'expiration de la fiche (calculee depuis date_sent, cf. Assetsign::runExpiration()),
     * pour que la page de signature annonce la vraie echeance et non un delai recommence.
     *
     * @throws \RuntimeException si la fiche n'existe pas ou n'attend pas la signature de $usersId
     */
   public static function createSigningToken(int $assetsignsId, int $usersId): string {
       $assetsign = new Assetsign();
      if (!$assetsign->getFromDB($assetsignsId)) {
          throw new \RuntimeException(__('Attribution introuvable.', 'assetsign'));
      }

       $role = self::roleOf($assetsign, $usersId);
      if ($role === null) {
          throw new \RuntimeException(__('Ce document n\'attend pas votre signature.', 'assetsign'));
      }

       $config = Config::getForEntity((int) $assetsign->fields['entities_id']);

       return Token::createForAssetsign(
           $assetsign,
           self::remainingValidityDays($assetsign, (int) $config->fields['link_validity_days']),
           forCosigner: $role === self::ROLE_COSIGNER
       );
   }

    /**
     * Jours restants avant expiration de la fiche, au moins 1 : une fiche dont le delai est
     * depasse mais pas encore passee en "Expiree" par la tache planifiee reste signable jusqu'a
     * ce passage, comme avec le lien de l'e-mail.
     */
   public static function remainingValidityDays(Assetsign $assetsign, int $validityDays): int {
       $dateSent = $assetsign->fields['date_sent'] ?? null;
      if (empty($dateSent)) {
          return max(1, $validityDays);
      }

       $elapsed = (int) floor((time() - strtotime($dateSent)) / DAY_TIMESTAMP);

       return max(1, $validityDays - $elapsed);
   }

    /**
     * Pre-filtre SQL (statut, destinataire) : roleOf() reste la regle de reference, ce critere
     * ne sert qu'a ne pas charger toutes les fiches.
     */
   private static function criteria(int $usersId): array {
       return [
           'is_deleted' => 0,
           'OR'         => [
               [
                   'status' => self::STATUSES_SIGNER,
                   'OR'     => [
                       ['users_id' => $usersId, 'delegated_users_id' => 0],
                       ['delegated_users_id' => $usersId],
                   ],
               ],
               [
                   'status'            => Assetsign::STATUS_AWAITING_COSIGNATURE,
                   'cosigner_users_id' => $usersId,
               ],
           ],
       ];
   }
}
