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
