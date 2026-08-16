<?php

namespace GlpiPlugin\Assetsign\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Classe de base pour les tests qui ecrivent en base : chaque test s'execute
 * dans une transaction annulee en tearDown, pour ne rien laisser derriere lui.
 * Les tables du plugin (et glpi_entities) sont en InnoDB, ce qui rend cela
 * possible — mais ce n'est pas un filet de securite absolu (une requete qui
 * ferait un COMMIT implicite, ex: DDL, echapperait au rollback). Ne PAS lancer
 * ces tests contre une base de production.
 */
abstract class AssetsignTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $DB;
        $DB->beginTransaction();

        // tests/bootstrap.php ne simule aucune connexion : $_SESSION reste
        // entierement vide (verifie en conditions reelles - Kernel::boot()
        // seul n'etablit rien). Sans droits GLPI natifs (Computer READ...),
        // CommonDBTM::can() echoue TOUJOURS, meme pour un test parfaitement
        // legitime - jusqu'ici invisible car aucun test n'appelait can()
        // avant les controles d'entite ajoutes dans Assetsign::createManual()/
        // Maintenance::createWithChecklist() (cf. TROUBLESHOOTING.md, faille
        // d'acces croise entre entites). Reproduit ici les droits du profil
        // Super-Admin (le seul realiste pour des tests qui manipulent tout
        // type de fiche) plutot que d'enumerer un a un chaque module touche.
        if (!isset($_SESSION['glpiactiveprofile'])) {
            $_SESSION['glpiactiveprofile'] = ['interface' => 'central'];
           foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => 4]]) as $row) {
               $_SESSION['glpiactiveprofile'][$row['name']] = (int) $row['rights'];
           }
        }
        $_SESSION['glpiactiveentities'] ??= [0];
        $_SESSION['glpiactiveentities_string'] ??= '0';
    }

    protected function tearDown(): void
    {
        global $DB;
        $DB->rollBack();

        parent::tearDown();
    }

    /**
     * Cree une entite de test directement en base (plus fiable qu'Entity::add(),
     * qui exige des champs supplementaires pour certains calculs internes non
     * pertinents ici) et renvoie son ID.
     */
    protected function createTestEntity(int $parentId, string $name): int
    {
        global $DB;

        static $nextId = null;
        if ($nextId === null) {
            // Plage tres improbable de collisionner avec des entites reelles.
            $nextId = random_int(500000, 599999);
        }
        $id = $nextId++;

        $parentLevel = 1;
        $ancestors = [0];
        if ($parentId !== 0) {
            foreach ($DB->request(['FROM' => 'glpi_entities', 'WHERE' => ['id' => $parentId]]) as $row) {
                $parentLevel = (int) $row['level'];
                $ancestors = array_merge(
                    json_decode((string) ($row['ancestors_cache'] ?? '[]'), true) ?: [],
                    [$parentId]
                );
            }
        }

        $DB->insert('glpi_entities', [
            'id'              => $id,
            'name'            => $name,
            'completename'    => $name,
            'entities_id'     => $parentId,
            'level'           => $parentLevel + 1,
            // ancestors_cache correctement rempli (meme mecanisme que
            // createTestState() pour glpi_states) : necessaire pour un vrai
            // arbre d'entites coherent, meme si ce n'est PAS ce que verifie
            // Session::haveAccessToEntity() ci-dessous (elle ne regarde que
            // $_SESSION['glpiactiveentities'], jamais ce cache directement).
            'ancestors_cache' => json_encode($ancestors),
        ]);

        // tests/bootstrap.php ne simule aucune connexion (pas de session GLPI
        // au sens normal) : $_SESSION['glpiactiveentities'] n'existe donc pas,
        // et Session::haveAccessToEntity() - utilisee par CommonDBTM::can(),
        // donc par les controles d'entite ajoutes dans Assetsign::createManual()/
        // Maintenance::createWithChecklist() (cf. TROUBLESHOOTING.md, faille
        // d'acces croise entre entites) - renverrait toujours faux, y compris
        // pour un test parfaitement legitime. On enregistre donc chaque entite
        // de test au fil de sa creation, plutot que de contourner le controle
        // avec Session::callAsSystem() qui desactiverait aussi ce qu'on
        // cherche justement a tester.
        $_SESSION['glpiactiveentities'][] = $id;
        $_SESSION['glpiactiveentities_string'] = implode("','", $_SESSION['glpiactiveentities']);

        return $id;
    }

    /**
     * Cree un Etat GLPI (glpi_states) directement en base, meme motif et
     * meme raison que createTestEntity() : State est aussi un CommonTreeDropdown
     * (level/ancestors_cache), mais rien dans ce plugin ne parcourt la
     * hierarchie des Etats — seul un id valide a referencer dans states_id
     * est necessaire ici.
     */
    protected function createTestState(string $name): int
    {
        global $DB;

        static $nextId = null;
        if ($nextId === null) {
            $nextId = random_int(600000, 699999);
        }
        $id = $nextId++;

        $DB->insert('glpi_states', [
            'id'              => $id,
            'name'            => $name,
            'completename'    => $name,
            'entities_id'     => 0,
            'states_id'       => 0,
            'level'           => 1,
            'ancestors_cache' => '[]',
            'sons_cache'      => '[]',
        ]);

        return $id;
    }

    /**
     * Utilisateur de test avec prenom/nom garantis (contrairement au compte
     * 'glpi' du jeu de test, dont le prenom/nom ne sont pas forcement remplis
     * sur une instance fraichement installee — constate en CI, absent en local
     * sur une instance deja manipulee manuellement).
     */
    protected function createTestUser(string $firstname, string $realname, array $extra = []): int
    {
        $user = new \User();
        return (int) $user->add(array_merge([
            'name'      => strtolower($firstname) . '.' . strtolower($realname) . '.' . random_int(100000, 999999),
            'firstname' => $firstname,
            'realname'  => $realname,
        ], $extra));
    }

    /** Cree un Computer minimal (nom + entite), pour les tests de declenchement. */
    protected function createTestComputer(int $entitiesId, string $name): \Computer
    {
        $computer = new \Computer();
        $id = (int) $computer->add([
            'name'        => $name,
            'entities_id' => $entitiesId,
        ]);
        $computer->getFromDB($id);
        return $computer;
    }

    /**
     * Cree une Assetsign minimale par insertion directe (pas via createManual()/
     * createAssetsign(), qui appellent launchWorkflow() et generent un vrai PDF) :
     * pour les tests dont la logique testee ne depend pas du PDF/du workflow
     * (Token, DamageMarker, Reminder...), evite le cout et les effets de bord
     * d'une generation PDF a chaque test.
     */
    protected function createBareAssetsign(int $entitiesId, int $type = 0, int $status = 1, int $usersId = 2): \GlpiPlugin\Assetsign\Assetsign
    {
        $assetsign = new \GlpiPlugin\Assetsign\Assetsign();
        $id = (int) $assetsign->add([
            'entities_id' => $entitiesId,
            'itemtype'    => 'Computer',
            'items_id'    => 1,
            'users_id'    => $usersId,
            'type'        => $type,
            'status'      => $status,
        ]);
        $assetsign->getFromDB($id);
        return $assetsign;
    }

    /**
     * Verifie l'absence d'un entier isole de 5+ chiffres entre deux balises
     * dans un HTML rendu — la signature d'un {{ call(...) }} Twig qui aurait
     * imprime par erreur la valeur de retour d'un Dropdown::show() sous-jacent
     * (cf. TemplateRenderingTest/OtherTemplateRenderingTest, bug reel corrige
     * en {% do call(...) %}, documente dans TROUBLESHOOTING.md). Partagee par
     * les deux classes de test qui rendent des gabarits d'administration,
     * plutot que dupliquee dans chacune.
     */
    protected function assertNoStrayNumericTextNode(string $html, string $message): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/>\s*\d{5,}\s*</',
            $html,
            $message . " (recherche d'un entier de 5+ chiffres isole entre deux balises, signature d'une valeur de retour de call() imprimee par erreur)"
        );
    }

    /**
     * Vide toutes les remises actuellement en attente de signature (SENT/VIEWED),
     * a l'interieur de la transaction du test en cours (donc sans effet reel,
     * annule au tearDown) : necessaire avant de tester runReminders()/
     * runExpiration()/runExpiryWarnings(), qui parcourent TOUTE la table sans
     * filtre d'entite — sans ce nettoyage, d'anciennes assetsigns de tests
     * manuels deja presentes fausseraient le compte retourne.
     */
    protected function clearAwaitingSignatureAssetsigns(): void
    {
        global $DB;
        $DB->update('glpi_plugin_assetsign_assetsigns', ['status' => \GlpiPlugin\Assetsign\Assetsign::STATUS_CANCELLED], [
            'status' => \GlpiPlugin\Assetsign\Assetsign::STATUSES_AWAITING_SIGNATURE,
        ]);
    }
}
