<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="cwpa-wrap">

  <!-- Header -->
  <div class="cwpa-header">
    <div class="cwpa-header-inner">
      <div class="cwpa-logo">
        <div class="cwpa-logo-icon">⬡</div>
        <div>
          <h1>Claude WP Assistant</h1>
          <span>Powered by Anthropic Claude AI</span>
        </div>
      </div>
      <div class="cwpa-api-status" id="cwpa-api-status">
        <?php $key = get_option('cwpa_api_key',''); ?>
        <?php if ($key): ?>
          <span class="cwpa-badge cwpa-badge-ok">● API connectée</span>
        <?php else: ?>
          <span class="cwpa-badge cwpa-badge-warn">● API non configurée</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- API Key Setup -->
  <?php if (!$key): ?>
  <div class="cwpa-card cwpa-setup-card" id="cwpa-setup">
    <div class="cwpa-setup-icon">🔑</div>
    <h2>Configuration de l'API Claude</h2>
    <p>Pour utiliser Claude WP Assistant, vous avez besoin d'une clé API Anthropic.<br>
    Obtenez-en une gratuitement sur <a href="https://console.anthropic.com" target="_blank">console.anthropic.com</a></p>
    <div class="cwpa-api-form">
      <input type="password" id="cwpa-api-key-input" placeholder="sk-ant-api03-..." autocomplete="off">
      <button class="cwpa-btn cwpa-btn-primary" id="cwpa-save-key">Enregistrer la clé</button>
    </div>
    <div id="cwpa-key-feedback"></div>
  </div>
  <?php endif; ?>

  <!-- Main Grid -->
  <div class="cwpa-main" <?php echo !$key ? 'style="opacity:0.4;pointer-events:none;"' : ''; ?> id="cwpa-main">

    <!-- Scan Modules -->
    <div class="cwpa-section">
      <h2 class="cwpa-section-title">Analyses disponibles</h2>
      <div class="cwpa-scans-grid">

        <div class="cwpa-scan-card" data-type="php_errors">
          <div class="cwpa-scan-icon">🐛</div>
          <h3>Erreurs PHP</h3>
          <p>Analyse les logs PHP, warnings, notices et erreurs fatales</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card" data-type="performance">
          <div class="cwpa-scan-icon">⚡</div>
          <h3>Performance</h3>
          <p>Détecte les options autoload, transients expirés, requêtes lentes</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card" data-type="plugins">
          <div class="cwpa-scan-icon">🔌</div>
          <h3>Plugins</h3>
          <p>Vérifie les conflits, mises à jour manquantes et plugins inutilisés</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card" data-type="security">
          <div class="cwpa-scan-icon">🔒</div>
          <h3>Sécurité</h3>
          <p>Vérifie les vulnérabilités, permissions et configurations risquées</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card" data-type="seo">
          <div class="cwpa-scan-icon">📈</div>
          <h3>SEO Technique</h3>
          <p>Analyse les balises, sitemap, robots.txt et structure SEO</p>
          <button class="cwpa-btn cwpa-btn-scan">Analyser</button>
          <div class="cwpa-scan-badge"></div>
        </div>

        <div class="cwpa-scan-card cwpa-scan-all">
          <div class="cwpa-scan-icon">🚀</div>
          <h3>Scan Complet</h3>
          <p>Lance toutes les analyses en séquence pour un rapport global</p>
          <button class="cwpa-btn cwpa-btn-primary cwpa-btn-scan-all">Tout analyser</button>
        </div>

      </div>
    </div>

    <!-- Results Panel -->
    <div class="cwpa-section" id="cwpa-results-section" style="display:none;">
      <h2 class="cwpa-section-title">Résultats <span id="cwpa-results-type"></span></h2>
      <div id="cwpa-results-container"></div>
    </div>

    <!-- Chat Panel -->
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

  </div><!-- .cwpa-main -->

  <!-- Loading Overlay -->
  <div class="cwpa-loading-overlay" id="cwpa-loading" style="display:none;">
    <div class="cwpa-loading-inner">
      <div class="cwpa-spinner"></div>
      <p id="cwpa-loading-text">Claude analyse votre site...</p>
    </div>
  </div>

</div><!-- .cwpa-wrap -->
