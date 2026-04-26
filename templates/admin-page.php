<?php if ( ! defined( 'ABSPATH' ) ) exit;
$claude_key = get_option('cwpa_api_key','');
?>
<div class="cwpa-wrap">

  <!-- Header -->
  <div class="cwpa-header">
    <div class="cwpa-header-inner">
      <div class="cwpa-logo">
        <div class="cwpa-logo-icon">⬡</div>
        <div>
          <h1>Claude WP Assistant</h1>
          <span>by Biristools · Powered by Claude AI</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span class="cwpa-badge <?php echo $claude_key ? 'cwpa-badge-ok' : 'cwpa-badge-warn'; ?>">
          <?php echo $claude_key ? '● Claude API connectée' : '● Claude API non configurée'; ?>
        </span>
        <span class="cwpa-badge cwpa-badge-info">v<?php echo CWPA_VERSION; ?></span>
        <button class="cwpa-btn cwpa-btn-ghost cwpa-btn-sm" id="cwpa-check-update">↻ Vérifier les mises à jour</button>
        <span id="cwpa-update-result" style="font-size:12px;"></span>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════
       SECTION PERFORMANCE — Indépendante de l'API Claude
  ════════════════════════════════════════════════════════════════════ -->
  <div class="cwpa-main">

    <!-- ── PAGESPEED ─────────────────────────────────────────────────── -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">PageSpeed Insights</h2>
      <div class="cwpa-card cwpa-pagespeed-form">
        <div class="cwpa-ps-inputs">
          <div class="cwpa-ps-url-row">
            <input type="url" id="cwpa-ps-url" value="<?php echo esc_attr(get_site_url()); ?>" placeholder="https://votresite.com">
            <select id="cwpa-ps-strategy">
              <option value="mobile">📱 Mobile</option>
              <option value="desktop">🖥 Desktop</option>
            </select>
            <button class="cwpa-btn cwpa-btn-primary" id="cwpa-ps-run">Analyser avec PageSpeed</button>
          </div>
          <div class="cwpa-ps-key-row">
            <input type="password" id="cwpa-ps-key" placeholder="Clé API Google (optionnelle, recommandée)" value="<?php echo esc_attr(get_option('cwpa_pagespeed_key','')); ?>">
            <button class="cwpa-btn cwpa-btn-ghost" id="cwpa-ps-save-key">Sauvegarder</button>
            <span class="cwpa-ps-key-hint">
              Sans clé : limites de quota s'appliquent ·
              <a href="https://developers.google.com/speed/docs/insights/v5/get-started" target="_blank">Obtenir une clé</a>
            </span>
          </div>
        </div>
      </div>
      <div id="cwpa-pagespeed-results" style="display:none;"></div>
    </div>

    <!-- ── DIAGNOSTIC ──────────────────────────────────────────────────── -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">Diagnostic serveur</h2>
      <div class="cwpa-card cwpa-diag-card">
        <div class="cwpa-diag-intro">
          <span>Vérifie la compatibilité de ce serveur : PHP, GD/WebP, Imagick, .htaccess, permissions, type de serveur.</span>
          <button class="cwpa-btn cwpa-btn-ghost cwpa-btn-sm" id="cwpa-run-diag">🔍 Lancer le diagnostic</button>
        </div>
        <div id="cwpa-diag-results" style="display:none;margin-top:16px;"></div>
      </div>
    </div>

    <!-- ── LCP ──────────────────────────────────────────────────────────── -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">Optimisation LCP (Largest Contentful Paint)</h2>
      <div class="cwpa-card" id="cwpa-lcp-card">
        <div class="cwpa-lcp-header">
          <div class="cwpa-lcp-desc">
            <strong>Précharge l'image principale de chaque page</strong> — ajoute <code>fetchpriority="high"</code> et <code>&lt;link rel="preload"&gt;</code> dans le <code>&lt;head&gt;</code>, active <code>&lt;link rel="preconnect"&gt;</code> vers les domaines tiers, et exclut la première image du lazy-load.
          </div>
          <label class="cwpa-toggle cwpa-lcp-toggle">
            <input type="checkbox" id="cwpa-lcp-toggle" <?php echo get_option('cwpa_lcp_enabled') ? 'checked' : ''; ?>>
            <span class="cwpa-toggle-slider"></span>
          </label>
        </div>
        <div class="cwpa-lcp-settings" id="cwpa-lcp-settings" style="<?php echo get_option('cwpa_lcp_enabled') ? '' : 'display:none;'; ?>margin-top:20px;border-top:1px solid var(--cwpa-border);padding-top:18px;">
          <div class="cwpa-lcp-field">
            <label>URL image LCP manuelle <span style="color:var(--cwpa-text2);font-size:11px;">(optionnel — laissez vide pour détection automatique)</span></label>
            <input type="url" id="cwpa-lcp-url" placeholder="https://votresite.com/images/hero.jpg" value="<?php echo esc_attr(get_option('cwpa_lcp_manual_url','')); ?>" style="width:100%;margin-top:6px;">
          </div>
          <div class="cwpa-lcp-field" style="margin-top:14px;">
            <label>Domaines preconnect <span style="color:var(--cwpa-text2);font-size:11px;">(un par ligne — fonts.googleapis.com ajouté automatiquement)</span></label>
            <textarea id="cwpa-lcp-domains" rows="3" style="width:100%;margin-top:6px;resize:vertical;"><?php echo esc_textarea(implode("\n",(array)get_option('cwpa_preconnect_domains',[]))); ?></textarea>
          </div>
          <div style="display:flex;align-items:center;gap:12px;margin-top:14px;">
            <button class="cwpa-btn cwpa-btn-primary" id="cwpa-lcp-save">Enregistrer</button>
            <span id="cwpa-lcp-result" style="font-size:12px;"></span>
          </div>
        </div>
      </div>
    </div>

    <!-- ── OPTIMISATIONS ─────────────────────────────────────────────── -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">Optimisations Performance</h2>
      <div class="cwpa-optim-grid" id="cwpa-optim-grid">
        <div class="cwpa-loading-inline">
          <div class="cwpa-spinner-sm"></div> Chargement du statut...
        </div>
      </div>
    </div>

    <!-- ── WEBP ──────────────────────────────────────────────────────── -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">Compression WebP</h2>
      <div class="cwpa-card cwpa-webp-card" id="cwpa-webp-panel">
        <div class="cwpa-loading-inline">
          <div class="cwpa-spinner-sm"></div> Chargement des stats...
        </div>
      </div>
    </div>

    <!-- ── SSH ──────────────────────────────────────────────────────────── -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">Accès SSH — Optimisations Serveur</h2>
      <div class="cwpa-card cwpa-ssh-card">
        <div class="cwpa-ssh-intro">
          <div>
            <strong>Connectez Claude WP Assistant à votre serveur via SSH</strong> pour aller plus loin que le .htaccess :
            modifier la config Nginx, vider l'OPcache, recharger les services, lire les logs d'erreur en temps réel.
          </div>
          <?php if (CWPA_SSH::has_phpseclib()): ?>
          <div class="cwpa-ssh-driver-ok">✓ Driver SSH : <strong>phpseclib</strong> (pur PHP — aucune extension requise)</div>
          <?php elseif (CWPA_SSH::has_native_ssh2()): ?>
          <div class="cwpa-ssh-driver-ok">✓ Driver SSH : <strong>extension php-ssh2</strong></div>
          <?php else: ?>
          <div class="cwpa-ssh-warn">⚠ Driver SSH introuvable. Le dossier <code>vendor/</code> est absent du plugin — réinstallez depuis le ZIP.</div>
          <?php endif; ?>
        </div>

        <!-- Formulaire connexion -->
        <div class="cwpa-ssh-form" id="cwpa-ssh-form">
          <div class="cwpa-ssh-fields">
            <div class="cwpa-ssh-field">
              <label>Hôte SSH</label>
              <input type="text" id="cwpa-ssh-host" placeholder="votreserveur.com ou 192.168.1.1" value="<?php $s=CWPA_SSH::get_settings(); echo esc_attr($s['host']??''); ?>">
            </div>
            <div class="cwpa-ssh-field cwpa-ssh-field-sm">
              <label>Port</label>
              <input type="number" id="cwpa-ssh-port" value="<?php echo esc_attr($s['port']??22); ?>" min="1" max="65535">
            </div>
            <div class="cwpa-ssh-field">
              <label>Utilisateur</label>
              <input type="text" id="cwpa-ssh-user" placeholder="root ou deploy" value="<?php echo esc_attr($s['user']??''); ?>">
            </div>
            <div class="cwpa-ssh-field">
              <label>Authentification</label>
              <select id="cwpa-ssh-auth">
                <option value="password" <?php echo ($s['auth']??'password')==='password'?'selected':''; ?>>Mot de passe</option>
                <option value="key"      <?php echo ($s['auth']??'')==='key'?'selected':''; ?>>Clé SSH privée</option>
              </select>
            </div>
          </div>
          <div id="cwpa-ssh-auth-password" class="cwpa-ssh-field" style="<?php echo ($s['auth']??'password')==='key'?'display:none':''; ?>">
            <label>Mot de passe SSH</label>
            <input type="password" id="cwpa-ssh-password" placeholder="••••••••" autocomplete="new-password">
            <span style="font-size:11px;color:var(--cwpa-text2);">Stocké chiffré (AES-256) en base de données.</span>
          </div>
          <div id="cwpa-ssh-auth-key" class="cwpa-ssh-field" style="<?php echo ($s['auth']??'password')!=='key'?'display:none':''; ?>">
            <label>Clé privée SSH (PEM)</label>
            <textarea id="cwpa-ssh-privkey" rows="5" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..." style="font-family:monospace;font-size:11px;width:100%;"></textarea>
          </div>
          <div class="cwpa-ssh-actions-row">
            <button class="cwpa-btn cwpa-btn-primary" id="cwpa-ssh-save">Enregistrer</button>
            <button class="cwpa-btn cwpa-btn-ghost" id="cwpa-ssh-test">🔌 Tester la connexion</button>
            <span id="cwpa-ssh-result" style="font-size:12px;"></span>
          </div>
        </div>

        <!-- Actions serveur -->
        <div class="cwpa-ssh-actions" id="cwpa-ssh-actions" style="margin-top:24px;border-top:1px solid var(--cwpa-border);padding-top:20px;">
          <div class="cwpa-ssh-actions-title">Actions disponibles</div>
          <div class="cwpa-ssh-btn-grid" id="cwpa-ssh-btn-grid">
            <!-- Rempli par JS -->
          </div>
          <div class="cwpa-ssh-output-wrap" id="cwpa-ssh-output-wrap" style="display:none;">
            <div class="cwpa-ssh-output-header">
              <span id="cwpa-ssh-output-label"></span>
              <button class="cwpa-btn cwpa-btn-ghost cwpa-btn-sm" id="cwpa-ssh-output-close">✕ Fermer</button>
            </div>
            <pre class="cwpa-ssh-output" id="cwpa-ssh-output"></pre>
          </div>
        </div>

      </div>
    </div>

  </div><!-- .cwpa-main (performance) -->

  <!-- ═══════════════════════════════════════════════════════════════════
       SECTION CLAUDE — Requiert la clé API
  ════════════════════════════════════════════════════════════════════ -->

  <!-- API Key Setup -->
  <?php if (!$claude_key): ?>
  <div class="cwpa-card cwpa-setup-card">
    <div class="cwpa-setup-icon">🔑</div>
    <h2>Configurer l'API Claude pour les analyses IA</h2>
    <p>Les optimisations ci-dessus fonctionnent sans clé. Pour les analyses IA (erreurs PHP, sécurité, SEO, plugins), configurez votre clé API Anthropic.</p>
    <p><a href="https://console.anthropic.com" target="_blank">Obtenir une clé sur console.anthropic.com →</a></p>
    <div class="cwpa-api-form">
      <input type="password" id="cwpa-api-key-input" placeholder="sk-ant-api03-..." autocomplete="off">
      <button class="cwpa-btn cwpa-btn-primary" id="cwpa-save-key">Enregistrer</button>
    </div>
    <div id="cwpa-key-feedback"></div>
  </div>
  <?php endif; ?>

  <div class="cwpa-main" <?php echo !$claude_key ? 'style="opacity:0.45;pointer-events:none;"' : ''; ?>>

    <!-- ── ANALYSES CLAUDE ──────────────────────────────────────────── -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">Analyses Claude AI</h2>
      <?php if (!$claude_key): ?>
      <p class="cwpa-locked-msg">🔒 Configurez votre clé API Claude pour activer les analyses.</p>
      <?php endif; ?>
      <div class="cwpa-scans-grid">

        <div class="cwpa-scan-card" data-type="php_errors">
          <div class="cwpa-scan-icon">🐛</div>
          <h3>Erreurs PHP</h3>
          <p>Logs PHP, warnings, notices, erreurs fatales</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card" data-type="performance">
          <div class="cwpa-scan-icon">⚡</div>
          <h3>Performance DB</h3>
          <p>Autoload, transients, révisions, tables volumineuses</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card" data-type="plugins">
          <div class="cwpa-scan-icon">🔌</div>
          <h3>Plugins</h3>
          <p>Conflits, mises à jour manquantes, plugins inactifs</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card" data-type="security">
          <div class="cwpa-scan-icon">🔒</div>
          <h3>Sécurité</h3>
          <p>Vulnérabilités, permissions, configurations risquées</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card" data-type="seo">
          <div class="cwpa-scan-icon">📈</div>
          <h3>SEO Technique</h3>
          <p>Balises, sitemap, robots.txt, structure SEO</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card cwpa-scan-all">
          <div class="cwpa-scan-icon">🚀</div>
          <h3>Scan Complet</h3>
          <p>Lance toutes les analyses en séquence</p>
          <button class="cwpa-btn cwpa-btn-primary cwpa-btn-scan-all">Tout analyser</button>
        </div>

      </div>
    </div>

    <!-- Results Panel -->
    <div class="cwpa-section" id="cwpa-results-section" style="display:none;">
      <h2 class="cwpa-section-title">Résultats <span id="cwpa-results-type"></span></h2>
      <div id="cwpa-results-container"></div>
    </div>

    <!-- ── CHAT ──────────────────────────────────────────────────────── -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">💬 Poser une question à Claude</h2>
      <div class="cwpa-chat-wrap">
        <div class="cwpa-chat-messages" id="cwpa-chat-messages">
          <div class="cwpa-chat-msg cwpa-chat-assistant">
            <div class="cwpa-chat-avatar">⬡</div>
            <div class="cwpa-chat-bubble">Bonjour ! Je suis Claude, votre assistant WordPress. Posez-moi une question sur votre site, un problème rencontré, ou demandez-moi d'expliquer un résultat d'analyse.</div>
          </div>
        </div>
        <div class="cwpa-chat-input-row">
          <textarea id="cwpa-chat-input" placeholder="Ex: Pourquoi mon site est lent ? Comment corriger cette erreur PHP ?..." rows="2"></textarea>
          <button class="cwpa-btn cwpa-btn-primary" id="cwpa-chat-send">Envoyer ↑</button>
        </div>
      </div>
    </div>

  </div><!-- .cwpa-main (claude) -->

  <!-- Loading Overlay -->
  <div class="cwpa-loading-overlay" id="cwpa-loading" style="display:none;">
    <div class="cwpa-loading-inner">
      <div class="cwpa-spinner"></div>
      <p id="cwpa-loading-text">Analyse en cours...</p>
    </div>
  </div>

</div><!-- .cwpa-wrap -->
