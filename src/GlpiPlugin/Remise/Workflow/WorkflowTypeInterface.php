<?php

namespace GlpiPlugin\Remise\Workflow;

/**
 * Contrat que doit remplir tout type de fiche gere par le plugin (Remise,
 * Restitution, et les types a venir : Don, Vente...). Chaque type vit dans
 * sa propre classe, enregistree une fois aupres de WorkflowTypeRegistry —
 * ajouter un type ne modifie donc jamais Remise.php, Template.php ni
 * HandoverPdfBuilder.php, seulement ce fichier + une ligne d'enregistrement
 * dans hook.php (meme convention que Provider\ProviderFactory).
 */
interface WorkflowTypeInterface
{
    public function getId(): int;

    /** Libelle traduit (session courante) : listes, formulaires, tableau de bord. */
    public function getLabel(): string;

    /**
     * Libelle fixe, JAMAIS traduit via __() : utilise pour le contenu du PDF
     * et le nom des Documents GLPI. Un PDF est un document archive, consulte
     * a des dates et par des personnes potentiellement differentes de celle
     * qui a declenche sa creation — le faire dependre de la langue de session
     * produirait un document dont la langue varie selon qui l'a genere (deja
     * constate en conditions reelles, cf. Remise::getCanonicalTypeLabel()).
     */
    public function getCanonicalLabel(): string;

    /** @return array{page_title: string, material_heading: string} */
    public function getPdfHeadings(): array;

    /** @return array{content: string, charter_content: string} */
    public function getDefaultTemplateContent(): array;
}
