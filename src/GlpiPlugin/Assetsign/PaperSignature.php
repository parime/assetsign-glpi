<?php

namespace GlpiPlugin\Assetsign;

use Document;
use Document_Item;

/**
 * Issue #276 : version papier d'une fiche, pour les cas ou la signature electronique n'est pas
 * possible (pas de compte, pas d'ecran, beneficiaire externe...).
 *
 * - Impression : le meme PDF que la fiche, pre-rempli (materiel, beneficiaire, conditions, charte),
 *   avec des cadres de signature vides pour chaque signataire et l'etat des lieux imprimable (cases
 *   d'etat, schemas, legende des dommages, notes manuscrites). Rien n'est enregistre comme signe :
 *   seule l'impression est tracee (fiche et historique du materiel).
 * - Retour du papier signe : le technicien joint le scan (PDF, JPEG ou PNG, type controle sur le
 *   contenu du fichier). C'est cette action explicite, et elle seule, qui fait passer la fiche a
 *   STATUS_SIGNED_ON_PAPER : statut distinct de la signature electronique. La preuve enregistree
 *   (Signature, methode « paper ») porte l'empreinte SHA-256 du scan, qui l'a joint et quand.
 */
final class PaperSignature
{
   public const VARIANT_FORM = 'form';
   public const VARIANT_CONDITION = 'condition';

    /** Extension acceptee => type MIME attendu (lu dans le contenu du fichier, pas annonce). */
   public const ALLOWED_SCAN_TYPES = [
       'pdf'  => 'application/pdf',
       'jpg'  => 'image/jpeg',
       'jpeg' => 'image/jpeg',
       'png'  => 'image/png',
   ];

   public const MAX_SCAN_BYTES = 20 * 1024 * 1024;

    /**
     * Statuts pour lesquels la version papier peut etre imprimee et son scan joint : tant que
     * personne n'a signe (brouillon compris, pour ne jamais envoyer d'e-mail), et la fiche d'un
     * beneficiaire externe, remise sans signature faute de compte GLPI. Pas l'attente de
     * contre-signature : le beneficiaire a deja signe electroniquement.
     */
   public const STATUSES = [
       Assetsign::STATUS_DRAFT,
       Assetsign::STATUS_PENDING,
       Assetsign::STATUS_SENT,
       Assetsign::STATUS_VIEWED,
       Assetsign::STATUS_COMPLETED_NO_SIGNATURE,
   ];

   public static function isAvailableFor(Assetsign $assetsign): bool {
       return empty($assetsign->fields['is_deleted'])
           && in_array((int) ($assetsign->fields['status'] ?? -1), self::STATUSES, true);
   }

    /**
     * PDF de la version papier ($variant : VARIANT_FORM, la fiche complete suivie de l'etat des
     * lieux, ou VARIANT_CONDITION, l'etat des lieux seul). Trace l'impression sur la fiche et dans
     * l'historique du materiel ; ne change jamais le statut.
     *
     * @throws \RuntimeException fiche plus imprimable ou droit insuffisant
     */
   public static function renderPdf(Assetsign $assetsign, string $variant, int $usersId): string {
      if (!in_array($variant, [self::VARIANT_FORM, self::VARIANT_CONDITION], true)) {
          throw new \RuntimeException(__('Version papier inconnue.', 'assetsign'));
      }
       self::assertAllowed($assetsign);

       $binary = (new Pdf\HandoverPdfBuilder())->renderPaperPdf($assetsign, $variant);

       $assetsign->update([
           'id'                        => $assetsign->getID(),
           'paper_printed_at'          => date('Y-m-d H:i:s'),
           'paper_printed_by_users_id' => $usersId,
       ]);
       self::logEverywhere($assetsign, sprintf(
           $variant === self::VARIANT_CONDITION
               ? __('État des lieux imprimable de la fiche n° %1$s généré par %2$s', 'assetsign')
               : __('Version papier de la fiche n° %1$s générée par %2$s, pour une signature à la main', 'assetsign'),
           Assetsign::displayReference($assetsign->fields),
           self::userName($usersId)
       ));

       return $binary;
   }

    /**
     * Joint le scan du papier signe et passe la fiche a « Signe sur papier ».
     *
     * @param string $path fichier deja recu (upload verifie par l'appelant)
     * @param string $declaredDate date de signature portee sur le papier (AAAA-MM-JJ), vide = aujourd'hui
     * @param array{ip?: string, user_agent?: string} $context
     *
     * @throws \RuntimeException droit, statut, fichier ou date refuses (message pour l'utilisateur)
     */
   public static function attachScan(Assetsign $assetsign, string $path, string $originalName, int $usersId, string $declaredDate = '', array $context = []): void {
       self::assertAllowed($assetsign);
       $extension = self::validateScan($path, $originalName);
       $signatureDate = self::validateDeclaredDate($assetsign, $declaredDate);
       $hash = hash_file('sha256', $path);
      if ($hash === false) {
          throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
      }

       $document = self::storeScan($assetsign, $path, $extension, $hash);
       $wasDraft = (int) $assetsign->fields['status'] === Assetsign::STATUS_DRAFT;

      if ($wasDraft) {
          // Brouillon jamais envoye : ce que l'envoi aurait fait (materiel remis avec attribue,
          // passeport), sans lien ni e-mail de signature.
          unset($_SESSION['plugin_assetsign_confirm'][$assetsign->getID()]);
          AssetsignItem::refreshSnapshots($assetsign->getID());
          PassportEvent::recordForAssetsign($assetsign);
          $assetsign->assignHandedItemsInGlpi();
          PassportEvent::recordHandedItems($assetsign);
      }

       $signer = $assetsign->getExpectedSigner();
       Signature::recordProofForAssetsign($assetsign, [
           'signer_name'      => Pdf\PdfMetadata::personName($signer),
           'signer_email'     => (string) ($signer['email'] ?? ''),
           'ip_address'       => mb_substr((string) ($context['ip'] ?? ''), 0, 46),
           'user_agent'       => mb_substr((string) ($context['user_agent'] ?? ''), 0, 512),
           'document_hash'    => $hash,
           'signed_at'        => date('Y-m-d H:i:s'),
           'signature_method' => Signature::METHOD_PAPER,
       ]);

       $assetsign->update([
           'id'                     => $assetsign->getID(),
           'status'                 => Assetsign::STATUS_SIGNED_ON_PAPER,
           'document_id_paper_scan' => $document->getID(),
           'paper_scan_users_id'    => $usersId,
           'paper_signature_date'   => $signatureDate,
           'date_signed'            => date('Y-m-d H:i:s'),
       ]);
       $assetsign->getFromDB($assetsign->getID());

       // Le lien de signature electronique deja envoye ne doit plus aboutir.
       Token::invalidateForAssetsign($assetsign->getID());

       self::logEverywhere($assetsign, sprintf(
           __('Fiche n° %1$s signée sur papier : scan joint par %2$s (signature datée du %3$s)', 'assetsign'),
           Assetsign::displayReference($assetsign->fields),
           self::userName($usersId),
           \Html::convDate($signatureDate)
       ));

       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $assetsign->notifyLinkedTicket((int) $document->getID(), true);
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;
   }

    /**
     * Controle du fichier recu : taille, extension, et type reel lu dans le contenu (un .png qui
     * contient autre chose est refuse), image decodable ou en-tete PDF.
     *
     * @return string extension normalisee
     *
     * @throws \RuntimeException
     */
   public static function validateScan(string $path, string $originalName): string {
      if (!is_file($path) || !is_readable($path)) {
          throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
      }
       $size = (int) filesize($path);
      if ($size <= 0) {
          throw new \RuntimeException(__('Le fichier du scan est vide.', 'assetsign'));
      }
      if ($size > self::MAX_SCAN_BYTES) {
          throw new \RuntimeException(sprintf(__('Le scan dépasse la taille maximale (%d Mo).', 'assetsign'), self::MAX_SCAN_BYTES / 1024 / 1024));
      }

       $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
      if (!isset(self::ALLOWED_SCAN_TYPES[$extension])) {
          throw new \RuntimeException(__('Type de fichier refusé : joignez un scan au format PDF, JPEG ou PNG.', 'assetsign'));
      }

       $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
      if ($mime !== self::ALLOWED_SCAN_TYPES[$extension]) {
          throw new \RuntimeException(__('Le contenu du fichier ne correspond pas à son extension : joignez un vrai scan au format PDF, JPEG ou PNG.', 'assetsign'));
      }
      if ($mime === 'application/pdf') {
          $head = (string) file_get_contents($path, false, null, 0, 5);
         if ($head !== '%PDF-') {
             throw new \RuntimeException(__('Le contenu du fichier ne correspond pas à son extension : joignez un vrai scan au format PDF, JPEG ou PNG.', 'assetsign'));
         }
      } else {
          $image = @getimagesize($path);
         if ($image === false || $image['mime'] !== $mime || (int) $image[0] <= 0 || (int) $image[1] <= 0) {
             throw new \RuntimeException(__('Le contenu du fichier ne correspond pas à son extension : joignez un vrai scan au format PDF, JPEG ou PNG.', 'assetsign'));
         }
      }

       return $extension === 'jpeg' ? 'jpg' : $extension;
   }

    /** @throws \RuntimeException */
   private static function assertAllowed(Assetsign $assetsign): void {
      if (!$assetsign->can($assetsign->getID(), UPDATE)) {
          throw new \RuntimeException(__('Vous n\'avez pas le droit de modifier cette fiche.', 'assetsign'));
      }
      if (!self::isAvailableFor($assetsign)) {
          throw new \RuntimeException(__('La version papier n\'est pas possible pour cette fiche (déjà signée, en attente du responsable, expirée ou annulée).', 'assetsign'));
      }
   }

    /**
     * Date de signature portee sur le papier : ni dans le futur, ni avant la creation de la fiche.
     *
     * @throws \RuntimeException
     */
   private static function validateDeclaredDate(Assetsign $assetsign, string $declaredDate): string {
       $declaredDate = trim($declaredDate);
      if ($declaredDate === '') {
          return date('Y-m-d');
      }
       $parsed = \DateTime::createFromFormat('!Y-m-d', $declaredDate);
      if ($parsed === false || $parsed->format('Y-m-d') !== $declaredDate) {
          throw new \RuntimeException(__('Date de signature invalide.', 'assetsign'));
      }
      if ($declaredDate > date('Y-m-d')) {
          throw new \RuntimeException(__('La date de signature ne peut pas être dans le futur.', 'assetsign'));
      }
       $created = substr((string) ($assetsign->fields['date_creation'] ?? ''), 0, 10);
      if ($created !== '' && $declaredDate < $created) {
          throw new \RuntimeException(__('La date de signature ne peut pas précéder la création de la fiche.', 'assetsign'));
      }
       return $declaredDate;
   }

    /**
     * Document GLPI du scan, rattache au materiel et au beneficiaire comme le PDF signe. Le fichier
     * enregistre est relu : son empreinte doit etre celle calculee sur le fichier recu.
     *
     * @throws \RuntimeException
     */
   private static function storeScan(Assetsign $assetsign, string $path, string $extension, string $hash): Document {
       $tmpName = uniqid('assetsign_paper_', true) . '.' . $extension;
      if (!copy($path, GLPI_TMP_DIR . '/' . $tmpName)) {
          throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
      }

       // Messages techniques du coeur (« Document copie avec succes ») : seul le message de
       // l'action doit s'afficher.
       $messagesBefore = $_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [];
       $document = new Document();
       $documents_id = (int) $document->add([
           // Nom fixe en francais, comme « [signée] » (cf. Assetsign::markSigned()).
           'name'          => $assetsign->getDocumentTitle() . ' [signée sur papier]',
           'comment'       => $assetsign->getDocumentComment() . ' - SHA-256 : ' . $hash,
           'entities_id'   => $assetsign->fields['entities_id'],
           '_filename'     => [$tmpName],
           '_tag_filename' => [$tmpName],
       ]);
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

      if ($documents_id <= 0 || !$document->getFromDB($documents_id)) {
          throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
      }
       $stored = GLPI_DOC_DIR . '/' . $document->fields['filepath'];
      if (!is_file($stored) || hash_file('sha256', $stored) !== $hash) {
          $document->delete(['id' => $documents_id], true);
          throw new \RuntimeException(__('Échec de l\'envoi du document.', 'assetsign'));
      }

      foreach ([[(string) $assetsign->fields['itemtype'], (int) $assetsign->fields['items_id']], ['User', (int) $assetsign->fields['users_id']]] as [$itemtype, $itemsId]) {
         if ($itemsId > 0) {
             (new Document_Item())->add(['documents_id' => $documents_id, 'itemtype' => $itemtype, 'items_id' => $itemsId]);
         }
      }
       $_SESSION['MESSAGE_AFTER_REDIRECT'] = $messagesBefore;

       return $document;
   }

    /** Historique de la fiche et du materiel (onglet Historique de GLPI). */
   private static function logEverywhere(Assetsign $assetsign, string $message): void {
       \Log::history($assetsign->getID(), Assetsign::class, ['0', '', $message], 0, \Log::HISTORY_LOG_SIMPLE_MESSAGE);
       $itemtype = (string) $assetsign->fields['itemtype'];
      if (is_a($itemtype, \CommonDBTM::class, true) && (int) $assetsign->fields['items_id'] > 0) {
          \Log::history((int) $assetsign->fields['items_id'], $itemtype, ['0', '', $message], 0, \Log::HISTORY_LOG_SIMPLE_MESSAGE);
      }
   }

   public static function userName(int $usersId): string {
       $user = new \User();
      if ($usersId <= 0 || !$user->getFromDB($usersId)) {
          return '-';
      }
       return Pdf\PdfMetadata::personName($user->fields) ?: '-';
   }
}
