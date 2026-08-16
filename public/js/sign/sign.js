(async function () {
    'use strict';

    var container = document.getElementById('pdf-container');
    var canvas = document.getElementById('signature-pad');
    var consentBox = document.getElementById('consent-checkbox');
    var btnSubmit = document.getElementById('btn-submit');
    var btnClear = document.getElementById('btn-clear');
    var statusMsg = document.getElementById('status-msg');

    if (!container) {
        return; // page affichée en mode "erreur", rien à câbler
    }

    // --- Prévisualisation défilante du PDF via PDF.js -------------------------------
    // Depuis PDF.js 5 (cf. TROUBLESHOOTING.md), plus aucun build UMD n'est distribué :
    // pdf.min.js est desormais un module ES, importe dynamiquement ici plutot que
    // charge via une balise <script> classique separee (qui echouerait, "export" etant
    // invalide hors contexte module). import() dynamique plutot qu'un `import` statique
    // en tete de fichier : un `import` statique n'accepte pas de chaine calculee, donc
    // pas de parametre `?v=` de cache-busting (meme piege deja documente et corrige
    // pour les balises <script> classiques) - le numero de version du plugin
    // (window.ASSETSIGN_PLUGIN_VERSION, injecte par sign_page.html.twig) est ainsi
    // repercute sur l'URL importee, exactement comme sur les balises <script>.
    var pdfjsLib = await import(
        (window.ASSETSIGN_ROOT_DOC || '') + '/plugins/assetsign/js/sign/vendor/pdf.min.js?v=' + encodeURIComponent(window.ASSETSIGN_PLUGIN_VERSION || '')
    );

    // window.ASSETSIGN_ROOT_DOC (injecte par sign_page.html.twig via config('root_doc'))
    // tient compte d'une installation GLPI dans un sous-dossier (ex: /glpi) : un
    // chemin absolu code en dur depuis la racine du domaine echouerait silencieusement
    // dans ce cas, empechant tout le reste du script de s'executer (PDF.js et
    // signature_pad dependent tous deux du bon chargement de ce worker).
    //
    // GlobalWorkerOptions.workerSrc (simple chaine d'URL) ne suffit plus depuis le
    // passage a PDF.js 6 (build module ES) : PDF.js decide en interne si le worker
    // doit demarrer en mode module via l'EXTENSION du fichier (`.mjs`) - or ce
    // fichier reste volontairement nomme `.js` ici (l'image Docker officielle GLPI
    // sert `.mjs` avec un Content-Type incorrect, `text/plain`, `.htaccess` desactive
    // par defaut - `AllowOverride None` - donc pas de correctif local possible cote
    // plugin). Avec `workerSrc`, PDF.js demarrait alors un worker CLASSIQUE (non
    // module) a partir d'un contenu qui contient pourtant `import`/`export`, echouant
    // en "Cannot use 'import.meta' outside a module". Corrige en construisant le
    // Worker nous-memes avec `{ type: 'module' }` explicite, assigne via
    // `workerPort` plutot que `workerSrc` (API alternative documentee par PDF.js).
    var pdfWorker = new Worker(
        (window.ASSETSIGN_ROOT_DOC || '') + '/plugins/assetsign/js/sign/vendor/pdf.worker.min.js?v=' + encodeURIComponent(window.ASSETSIGN_PLUGIN_VERSION || ''),
        { type: 'module' }
    );
    pdfjsLib.GlobalWorkerOptions.workerPort = pdfWorker;

    // { url: ... } explicite plutot qu'une chaine nue en argument direct : plus
    // fiable inter-versions (forme documentee sans ambiguite par PDF.js).
    pdfjsLib.getDocument({ url: container.dataset.pdfUrl }).promise.then(function (pdf) {
        var renderPage = function (num) {
            pdf.getPage(num).then(function (page) {
                var viewport = page.getViewport({ scale: 1.4 });
                var pageCanvas = document.createElement('canvas');
                pageCanvas.width = viewport.width;
                pageCanvas.height = viewport.height;
                container.appendChild(pageCanvas);
                page.render({ canvasContext: pageCanvas.getContext('2d'), viewport: viewport });
            });
        };
        for (var i = 1; i <= pdf.numPages; i++) {
            renderPage(i);
        }
    }).catch(function (err) {
        container.textContent = window.ASSETSIGN_I18N.loadError + ' (' + err.message + ').';
    });

    // --- Capture de signature (souris / tactile / stylet) ---------------------------
    function resizeCanvas() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        canvas.width = canvas.offsetWidth * ratio;
        canvas.height = canvas.offsetHeight * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        signaturePad.clear();
    }
    var signaturePad = new SignaturePad(canvas, { penColor: '#16232f' });
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    function updateSubmitState() {
        btnSubmit.disabled = signaturePad.isEmpty() || !consentBox.checked;
    }
    consentBox.addEventListener('change', updateSubmitState);
    signaturePad.addEventListener('endStroke', updateSubmitState);

    btnClear.addEventListener('click', function () {
        signaturePad.clear();
        updateSubmitState();
    });

    // window.assetsignQueuedFetch : defini par csrf-queue.js (charge avant ce
    // script, cf. sign_page.html.twig), partage avec damage-annotation.js et
    // le script inline de la Remarque.
    function post(action, extra) {
        return window.assetsignQueuedFetch(window.location.pathname, function () {
            var body = new URLSearchParams(Object.assign({
                t: window.ASSETSIGN_SIGN_TOKEN,
                action: action,
                _glpi_csrf_token: window.ASSETSIGN_CSRF_TOKEN
            }, extra || {}));
            return {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            };
        }).then(function (res) { return res.json(); });
    }

    btnSubmit.addEventListener('click', function () {
        btnSubmit.disabled = true;
        statusMsg.textContent = window.ASSETSIGN_I18N.sending;

        post('sign', { signature: signaturePad.toDataURL('image/png') }).then(function (data) {
            if (data.success) {
                // Racine de GLPI (pas /front/central.php en dur, page par defaut de
                // l'interface "centrale" mais pas forcement de CET utilisateur) :
                // la racine redirige chacun vers sa vraie page d'accueil configuree.
                var homeUrl = (window.ASSETSIGN_ROOT_DOC || '') + '/';
                var wrap = document.createElement('div');
                wrap.className = 'wrap';
                var card = document.createElement('div');
                card.className = 'card';
                var p1 = document.createElement('p');
                p1.textContent = window.ASSETSIGN_I18N.signedOk;
                var p2 = document.createElement('p');
                var link = document.createElement('a');
                link.href = homeUrl;
                link.textContent = window.ASSETSIGN_I18N.backToHome;
                p2.appendChild(link);
                card.appendChild(p1);
                card.appendChild(p2);
                wrap.appendChild(card);
                document.body.textContent = '';
                document.body.appendChild(wrap);
            } else {
                statusMsg.textContent = window.ASSETSIGN_I18N.errorPrefix + ' : ' + data.error;
                btnSubmit.disabled = false;
            }
        }).catch(function () {
            statusMsg.textContent = window.ASSETSIGN_I18N.networkError;
            btnSubmit.disabled = false;
        });
    });
})();
