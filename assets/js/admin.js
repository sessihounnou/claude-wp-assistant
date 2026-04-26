(function($){
  'use strict';

  var chatHistory = [];
  var lastScanData = {};
  var scanLabels = { php_errors:'Erreurs PHP', performance:'Performance DB', plugins:'Plugins', security:'Sécurité', seo:'SEO' };
  var scanTypes  = ['php_errors','performance','plugins','security','seo'];

  // ══════════════════════════════════════════════════════════
  // UPDATE CHECK
  // ══════════════════════════════════════════════════════════
  $('#cwpa-check-update').on('click', function(){
    var $btn = $(this).prop('disabled', true).text('Vérification...');
    var $res = $('#cwpa-update-result');
    $res.text('').css('color','');

    $.post(CWPA.ajax_url, { action:'cwpa_force_update_check', nonce:CWPA.nonce }, function(res){
      $btn.prop('disabled', false).text('↻ Vérifier les mises à jour');
      if (!res.success) {
        $res.text('⚠ ' + res.data).css('color','var(--cwpa-warning)');
        return;
      }
      var d = res.data;
      if (d.has_update) {
        $res.html('🚀 v'+d.latest+' disponible ! <a href="'+d.update_url+'" style="color:var(--cwpa-accent)">Mettre à jour →</a>').css('color','var(--cwpa-ok)');
      } else {
        $res.text('✓ v'+d.current+' — Plugin à jour.').css('color','var(--cwpa-text2)');
      }
    }).fail(function(){
      $btn.prop('disabled', false).text('↻ Vérifier les mises à jour');
      $res.text('⚠ Erreur réseau.').css('color','var(--cwpa-critical)');
    });
  });

  // ══════════════════════════════════════════════════════════
  // API KEY
  // ══════════════════════════════════════════════════════════
  $('#cwpa-save-key').on('click', function(){
    var key = $('#cwpa-api-key-input').val().trim();
    if (!key || !key.startsWith('sk-ant-')) {
      $('#cwpa-key-feedback').html('<span class="cwpa-fb-error">⚠ Clé invalide. Elle doit commencer par sk-ant-</span>');
      return;
    }
    var $btn = $(this).prop('disabled', true).text('Enregistrement...');
    $.post(CWPA.ajax_url, { action:'cwpa_save_key', nonce:CWPA.nonce, api_key:key }, function(res){
      if (res.success) {
        $('#cwpa-key-feedback').html('<span class="cwpa-fb-ok">✓ Clé enregistrée ! Rechargement...</span>');
        setTimeout(function(){ location.reload(); }, 1000);
      } else {
        $('#cwpa-key-feedback').html('<span class="cwpa-fb-error">Erreur: '+res.data+'</span>');
        $btn.prop('disabled', false).text('Enregistrer');
      }
    });
  });

  // ══════════════════════════════════════════════════════════
  // CLAUDE SCANS
  // ══════════════════════════════════════════════════════════
  $('.cwpa-btn-scan').on('click', function(){
    runScan($(this).closest('.cwpa-scan-card').data('type'));
  });

  $('.cwpa-btn-scan-all').on('click', function(){
    runAllScans(0, []);
  });

  function runAllScans(index, results){
    if (index >= scanTypes.length) {
      showLoading(false);
      renderAllResults(results);
      return;
    }
    var type = scanTypes[index];
    showLoading(true, 'Analyse '+scanLabels[type]+' ('+(index+1)+'/'+scanTypes.length+')...');
    $.post(CWPA.ajax_url, { action:'cwpa_scan', nonce:CWPA.nonce, scan_type:type }, function(res){
      if (res.success) { results.push({type:type, data:res.data.data}); updateScanBadge(type, res.data.data); }
      runAllScans(index+1, results);
    }).fail(function(){ runAllScans(index+1, results); });
  }

  function runScan(type){
    showLoading(true, 'Claude analyse '+scanLabels[type]+'...');
    $.post(CWPA.ajax_url, { action:'cwpa_scan', nonce:CWPA.nonce, scan_type:type }, function(res){
      showLoading(false);
      if (!res.success) { alert('Erreur: '+res.data); return; }
      var data = res.data.data;
      lastScanData[type] = data;
      updateScanBadge(type, data);
      renderResults(type, data);
    }).fail(function(){ showLoading(false); alert('Erreur réseau.'); });
  }

  function renderResults(type, data){
    var $s = $('#cwpa-results-section').show();
    $('#cwpa-results-type').text('— '+scanLabels[type]);
    $('#cwpa-results-container').html(buildResultsHTML(type, data));
    scrollTo($s);
  }

  function renderAllResults(results){
    var html = '';
    results.forEach(function(r){
      html += '<div style="margin-bottom:24px"><div class="cwpa-section-title" style="margin-bottom:12px">'+scanLabels[r.type]+'</div>'+buildResultsHTML(r.type, r.data)+'</div>';
    });
    $('#cwpa-results-section').show();
    $('#cwpa-results-type').text('— Complet');
    $('#cwpa-results-container').html(html);
    scrollTo($('#cwpa-results-section'));
  }

  function buildResultsHTML(type, data){
    if (!data) return '<div class="cwpa-card"><p style="color:#9090a8">Aucune donnée reçue.</p></div>';

    var score = data.score || 0;
    var scoreClass = score >= 80 ? 'good' : score >= 50 ? 'medium' : 'bad';
    var html = '<div class="cwpa-results-header">';
    html += '<div class="cwpa-score"><div class="cwpa-score-circle '+scoreClass+'">'+score+'</div>';
    html += '<div class="cwpa-score-info"><h3>Score '+scanLabels[type]+'</h3><p>'+escHtml(data.summary||'')+'</p></div></div>';
    if (data.priority_action) {
      html += '<div class="cwpa-priority"><strong>⚡ Action prioritaire</strong>'+escHtml(data.priority_action)+'</div>';
    }
    html += '</div>';

    var issues = data.issues || [];
    if (!issues.length) {
      return html + '<div class="cwpa-issues-list"><div class="cwpa-issue"><span class="cwpa-issue-sev info"></span><div class="cwpa-issue-body"><p class="cwpa-issue-title" style="color:#4dde94">✓ Aucun problème détecté</p></div></div></div>';
    }

    issues.sort(function(a,b){ var o={critical:0,warning:1,info:2}; return (o[a.severity]||2)-(o[b.severity]||2); });
    html += '<div class="cwpa-issues-list">';
    issues.forEach(function(issue, i){
      var fixId  = issue.fix_id || '';
      var canFix = issue.auto_fixable && fixId;
      html += '<div class="cwpa-issue">';
      html += '<span class="cwpa-issue-sev '+(issue.severity||'info')+'"></span>';
      html += '<div class="cwpa-issue-body">';
      html += '<div class="cwpa-issue-title">'+escHtml(issue.title||'')+'</div>';
      html += '<div class="cwpa-issue-desc">'+escHtml(issue.description||'')+'</div>';
      if (issue.fix_suggestion) html += '<div class="cwpa-issue-fix">💡 '+escHtml(issue.fix_suggestion)+'</div>';
      html += '<div class="cwpa-issue-actions">';
      if (canFix) {
        html += '<button class="cwpa-btn cwpa-btn-fix" data-fix="'+escAttr(fixId)+'">✓ Corriger automatiquement</button>';
        html += '<span class="cwpa-fix-result" id="fix-result-'+i+'"></span>';
      }
      html += '</div></div></div>';
    });
    html += '</div>';
    return html;
  }

  // ══════════════════════════════════════════════════════════
  // FIX (delegated — covers both scan results and optimizer)
  // ══════════════════════════════════════════════════════════
  $(document).on('click', '.cwpa-btn-fix', function(){
    var $btn  = $(this).prop('disabled', true).text('En cours...');
    var fixId = $btn.data('fix');
    var $res  = $btn.siblings('.cwpa-fix-result');

    $.post(CWPA.ajax_url, { action:'cwpa_fix', nonce:CWPA.nonce, fix_id:fixId }, function(res){
      if (res.success && res.data.success) {
        $res.removeClass('error').addClass('success').text('✓ '+res.data.message).show();
        $btn.text('✓ Corrigé').css('opacity','0.5');
        if (res.data.start_webp) startWebPConversion();
        // Refresh optimizer toggles if relevant
        if (fixId.indexOf('_emojis')>-1||fixId.indexOf('_embeds')>-1||fixId.indexOf('heartbeat')>-1||
            fixId.indexOf('defer')>-1||fixId.indexOf('lazy')>-1||fixId.indexOf('minify')>-1||
            fixId.indexOf('query_strings')>-1||fixId.indexOf('dns_prefetch')>-1||
            fixId.indexOf('cache')>-1||fixId.indexOf('gzip')>-1||fixId.indexOf('browser_cache')>-1||
            fixId.indexOf('webp')>-1) {
          setTimeout(loadOptimizerStatus, 500);
        }
      } else {
        var msg = (res.data && res.data.message) ? res.data.message : res.data;
        $res.removeClass('success').addClass('error').text('✗ '+msg).show();
        $btn.prop('disabled', false).text('Corriger automatiquement');
      }
    }).fail(function(){
      $res.removeClass('success').addClass('error').text('✗ Erreur réseau').show();
      $btn.prop('disabled', false).text('Corriger automatiquement');
    });
  });

  // ══════════════════════════════════════════════════════════
  // PAGESPEED
  // ══════════════════════════════════════════════════════════
  $('#cwpa-ps-save-key').on('click', function(){
    var key = $('#cwpa-ps-key').val().trim();
    var $btn = $(this).prop('disabled', true).text('Sauvegarde...');
    $.post(CWPA.ajax_url, { action:'cwpa_save_pagespeed_key', nonce:CWPA.nonce, pagespeed_key:key }, function(res){
      $btn.prop('disabled', false).text(res.success ? '✓ Sauvegardé' : 'Sauvegarder');
      setTimeout(function(){ $btn.text('Sauvegarder'); }, 2000);
    });
  });

  $('#cwpa-ps-run').on('click', function(){
    var url      = $('#cwpa-ps-url').val().trim();
    var strategy = $('#cwpa-ps-strategy').val();
    if (!url) { alert('Entrez une URL à analyser.'); return; }

    showLoading(true, 'Analyse PageSpeed en cours (peut prendre 30s)...');
    $.post(CWPA.ajax_url, { action:'cwpa_pagespeed', nonce:CWPA.nonce, url:url, strategy:strategy }, function(res){
      showLoading(false);
      if (!res.success) { alert('Erreur PageSpeed: '+res.data); return; }
      renderPageSpeed(res.data);
    }).fail(function(){ showLoading(false); alert('Erreur réseau.'); });
  });

  function renderPageSpeed(d){
    var scoreColor = function(s){ return s >= 90 ? '#4dde94' : s >= 50 ? '#ffb347' : '#ff4d6d'; };
    var scoreLabel = function(s){ return s >= 90 ? 'good' : s >= 50 ? 'medium' : 'bad'; };
    var stratLabel = d.strategy === 'mobile' ? '📱 Mobile' : '🖥 Desktop';

    // 4 main score circles
    var scores = [
      { key:'performance',    label:'Performance' },
      { key:'accessibility',  label:'Accessibilité' },
      { key:'best_practices', label:'Best Practices' },
      { key:'seo',            label:'SEO' },
    ];
    var scoresHtml = '<div class="cwpa-ps-scores">';
    scores.forEach(function(s){
      var val = d.scores[s.key] || 0;
      scoresHtml += '<div class="cwpa-ps-score-item">';
      scoresHtml += '<div class="cwpa-ps-circle '+scoreLabel(val)+'" style="--pct:'+val+'"><span>'+val+'</span></div>';
      scoresHtml += '<div class="cwpa-ps-score-label">'+s.label+'</div></div>';
    });
    scoresHtml += '</div>';

    // Core Web Vitals
    var cwvHtml = '<div class="cwpa-cwv-grid">';
    $.each(d.cwv, function(k, m){
      cwvHtml += '<div class="cwpa-cwv-item cwpa-cwv-'+m.status+'">';
      cwvHtml += '<div class="cwpa-cwv-value">'+escHtml(m.value)+'</div>';
      cwvHtml += '<div class="cwpa-cwv-label">'+escHtml(m.label)+'</div>';
      cwvHtml += '</div>';
    });
    cwvHtml += '</div>';

    // Opportunities
    var oppsHtml = '';
    if (d.opportunities && d.opportunities.length) {
      oppsHtml = '<div class="cwpa-issues-list">';
      d.opportunities.forEach(function(opp, i){
        var canFix = opp.auto_fixable && opp.fix_id;
        oppsHtml += '<div class="cwpa-issue">';
        oppsHtml += '<span class="cwpa-issue-sev '+opp.severity+'"></span>';
        oppsHtml += '<div class="cwpa-issue-body">';
        oppsHtml += '<div class="cwpa-issue-title">'+escHtml(opp.title)+'</div>';
        if (opp.savings) oppsHtml += '<div class="cwpa-ps-savings">💰 '+escHtml(opp.savings)+'</div>';
        oppsHtml += '<div class="cwpa-issue-desc">'+escHtml(opp.description.replace(/\[([^\]]+)\]\([^\)]+\)/g,'$1'))+'</div>';
        oppsHtml += '<div class="cwpa-issue-actions">';
        if (canFix) {
          oppsHtml += '<button class="cwpa-btn cwpa-btn-fix" data-fix="'+escAttr(opp.fix_id)+'">✓ Corriger automatiquement</button>';
          oppsHtml += '<span class="cwpa-fix-result" id="ps-fix-'+i+'"></span>';
        }
        oppsHtml += '</div></div></div>';
      });
      oppsHtml += '</div>';
    } else {
      oppsHtml = '<p style="color:#4dde94;padding:16px 0;">✓ Aucune opportunité critique détectée.</p>';
    }

    var html = '<div class="cwpa-ps-header"><strong>'+stratLabel+'</strong> · <span style="color:#9090a8">'+escHtml(d.url)+'</span></div>';
    html += scoresHtml;
    html += '<h3 class="cwpa-ps-subtitle">Core Web Vitals</h3>'+cwvHtml;
    html += '<h3 class="cwpa-ps-subtitle">Opportunités d\'optimisation</h3>'+oppsHtml;

    $('#cwpa-pagespeed-results').html(html).show();
    scrollTo($('#cwpa-pagespeed-results'));
  }

  // ══════════════════════════════════════════════════════════
  // OPTIMIZER PANEL
  // ══════════════════════════════════════════════════════════
  var optimDefs = [
    { id:'page_cache',           label:'Cache de pages',         desc:'Sert les pages HTML en cache (non connectés)',  on:'enable_page_cache',     off:'disable_page_cache',    icon:'📄' },
    { id:'gzip',                 label:'Compression GZIP',       desc:'Compresse le HTML/CSS/JS côté serveur',          on:'enable_gzip',           off:'disable_gzip',          icon:'🗜' },
    { id:'browser_cache',        label:'Cache navigateur',       desc:'Définit les headers Expires/Cache-Control',      on:'enable_browser_cache',  off:'disable_browser_cache', icon:'🌐' },
    { id:'disable_emojis',       label:'Désactiver emojis',      desc:'Supprime les scripts emojis WordPress',          on:'disable_emojis',        off:'enable_emojis',         icon:'😊' },
    { id:'disable_embeds',       label:'Désactiver embeds',      desc:'Supprime le script oEmbed WordPress',            on:'disable_embeds',        off:'enable_embeds',         icon:'📎' },
    { id:'heartbeat_control',    label:'Heartbeat control',      desc:'Réduit l\'intervalle Heartbeat à 60s',           on:'heartbeat_control',     off:'disable_heartbeat_control', icon:'💓' },
    { id:'defer_js',             label:'Différer le JS',         desc:'Ajoute defer aux scripts non-critiques',         on:'defer_js',              off:'disable_defer_js',      icon:'⏳' },
    { id:'lazy_load',            label:'Lazy load images',       desc:'Charge les images hors-écran en différé',        on:'enable_lazy_load',      off:'disable_lazy_load',     icon:'🖼' },
    { id:'html_minify',          label:'Minification HTML',      desc:'Compresse le HTML en supprimant les espaces',    on:'enable_html_minify',    off:'disable_html_minify',   icon:'📝' },
    { id:'remove_query_strings', label:'Remove query strings',   desc:'Supprime ?ver= des ressources statiques',        on:'remove_query_strings',  off:'disable_query_strings', icon:'🔗' },
    { id:'dns_prefetch',         label:'DNS Prefetch',           desc:'Préconnecte aux domaines tiers (fonts, CDN)',     on:'enable_dns_prefetch',   off:'disable_dns_prefetch',  icon:'📡' },
    { id:'webp_serving',         label:'Servir WebP (.htaccess)','desc':'Redirige auto vers .webp si disponible',       on:'enable_webp_serving',   off:'disable_webp_serving',  icon:'🌅' },
    { id:'webp_auto',            label:'Auto-convert WebP',      desc:'Convertit les nouvelles images à l\'upload',     on:'enable_webp_auto',      off:'disable_webp_auto',     icon:'⚙️' },
  ];

  function loadOptimizerStatus(){
    $.post(CWPA.ajax_url, { action:'cwpa_optimizer_status', nonce:CWPA.nonce }, function(res){
      if (!res.success) return;
      renderOptimizer(res.data.status, res.data.cache);
    });
  }

  function renderOptimizer(status, cache){
    var html = '<div class="cwpa-optim-cards">';
    optimDefs.forEach(function(opt){
      var active = status[opt.id] || false;
      var fixId  = active ? opt.off : opt.on;
      html += '<div class="cwpa-optim-card'+(active?' cwpa-optim-active':'')+'">';
      html += '<div class="cwpa-optim-icon">'+opt.icon+'</div>';
      html += '<div class="cwpa-optim-info"><div class="cwpa-optim-label">'+escHtml(opt.label)+'</div>';
      html += '<div class="cwpa-optim-desc">'+escHtml(opt.desc)+'</div></div>';
      html += '<div class="cwpa-optim-actions">';
      html += '<label class="cwpa-toggle"><input type="checkbox" class="cwpa-toggle-input" data-fix="'+escAttr(fixId)+'" data-id="'+escAttr(opt.id)+'" '+(active?'checked':'')+'>'+
              '<span class="cwpa-toggle-slider"></span></label>';
      html += '<span class="cwpa-optim-status '+(active?'active':'inactive')+'">'+(active?'Actif':'Inactif')+'</span>';
      html += '</div></div>';
    });
    html += '</div>';

    // Cache stats bar
    if (cache) {
      html += '<div class="cwpa-cache-bar">';
      html += '<span>📄 Cache pages: <strong>'+cache.files+' fichiers</strong> · '+cache.size_kb+' KB</span>';
      html += '<button class="cwpa-btn cwpa-btn-ghost" id="cwpa-clear-cache">Vider le cache</button>';
      html += '</div>';
    }

    $('#cwpa-optim-grid').html(html);
  }

  $(document).on('change', '.cwpa-toggle-input', function(){
    var $cb    = $(this);
    var fixId  = $cb.data('fix');
    var $card  = $cb.closest('.cwpa-optim-card');
    $cb.prop('disabled', true);

    $.post(CWPA.ajax_url, { action:'cwpa_fix', nonce:CWPA.nonce, fix_id:fixId }, function(res){
      $cb.prop('disabled', false);
      if (res.success && res.data.success) {
        setTimeout(loadOptimizerStatus, 300);
      } else {
        $cb.prop('checked', !$cb.prop('checked')); // revert
        alert('Erreur: ' + (res.data && res.data.message ? res.data.message : res.data));
      }
    }).fail(function(){
      $cb.prop('disabled', false).prop('checked', !$cb.prop('checked'));
    });
  });

  $(document).on('click', '#cwpa-clear-cache', function(){
    var $btn = $(this).prop('disabled', true).text('Vidage...');
    $.post(CWPA.ajax_url, { action:'cwpa_cache_clear', nonce:CWPA.nonce }, function(res){
      if (res.success) {
        $btn.text('✓ Vidé').css('color','var(--cwpa-ok)');
        setTimeout(loadOptimizerStatus, 500);
      } else {
        $btn.prop('disabled', false).text('Vider le cache');
      }
    });
  });

  // ══════════════════════════════════════════════════════════
  // WEBP
  // ══════════════════════════════════════════════════════════
  function loadWebPStats(){
    $.post(CWPA.ajax_url, { action:'cwpa_webp_stats', nonce:CWPA.nonce }, function(res){
      if (!res.success) return;
      renderWebP(res.data);
    });
  }

  function renderWebP(s){
    var driverBadge = s.driver
      ? '<span class="cwpa-badge cwpa-badge-ok">● '+escHtml(s.driver.toUpperCase())+' disponible</span>'
      : '<span class="cwpa-badge cwpa-badge-warn">● Aucun driver WebP (GD/Imagick requis)</span>';

    var pct  = s.percent || 0;
    var html = '<div class="cwpa-webp-header">';
    html += '<div class="cwpa-webp-stats">';
    html += '<div class="cwpa-webp-stat"><span class="cwpa-webp-num">'+s.converted+'</span><span>Converties</span></div>';
    html += '<div class="cwpa-webp-stat"><span class="cwpa-webp-num">'+s.pending+'</span><span>En attente</span></div>';
    html += '<div class="cwpa-webp-stat"><span class="cwpa-webp-num">'+s.saved_kb+' KB</span><span>Économisés</span></div>';
    html += '<div class="cwpa-webp-stat"><span class="cwpa-webp-num">'+pct+'%</span><span>Progression</span></div>';
    html += '</div>';
    html += '<div class="cwpa-webp-right">'+driverBadge;
    if (s.driver && s.pending > 0) {
      html += '<button class="cwpa-btn cwpa-btn-primary" id="cwpa-webp-convert">Convertir '+s.pending+' images en WebP</button>';
    } else if (s.pending === 0 && s.total > 0) {
      html += '<span style="color:var(--cwpa-ok)">✓ Toutes les images sont converties</span>';
    }
    html += '</div></div>';

    // Progress bar
    html += '<div class="cwpa-webp-progress-wrap"><div class="cwpa-webp-progress-bar" style="width:'+pct+'%"></div></div>';
    html += '<div class="cwpa-webp-progress-text" id="cwpa-webp-log"></div>';

    if (!s.driver) {
      html += '<p class="cwpa-webp-note">Pour activer la conversion WebP, activez l\'extension <strong>GD</strong> (avec imagewebp) ou <strong>Imagick</strong> sur votre serveur.</p>';
    }

    $('#cwpa-webp-panel').html(html);
  }

  $(document).on('click', '#cwpa-webp-convert', function(){
    $(this).prop('disabled', true).text('Conversion en cours...');
    startWebPConversion();
  });

  function startWebPConversion(){
    processBatch(0);
  }

  function processBatch(offset){
    $.post(CWPA.ajax_url, { action:'cwpa_webp_convert', nonce:CWPA.nonce, offset:offset }, function(res){
      if (!res.success) {
        $('#cwpa-webp-log').text('Erreur: '+(res.data||'inconnue'));
        return;
      }
      var d = res.data;
      var done  = d.done_so_far || offset;
      var total = d.total || 1;
      var pct   = Math.min(100, Math.round((done/total)*100));

      $('.cwpa-webp-progress-bar').css('width', pct+'%');
      $('#cwpa-webp-log').text('Converti '+d.converted+' · Ignoré '+d.skipped+' · '+done+'/'+total+' traités ('+pct+'%)');

      if (d.errors && d.errors.length) {
        $('#cwpa-webp-log').append(' · Erreurs: '+d.errors.join(', '));
      }

      if (d.has_more) {
        setTimeout(function(){ processBatch(d.next_offset); }, 200);
      } else {
        $('#cwpa-webp-log').prepend('✓ Terminé — ');
        setTimeout(function(){ loadWebPStats(); loadOptimizerStatus(); }, 500);
      }
    }).fail(function(){
      $('#cwpa-webp-log').text('Erreur réseau lors de la conversion.');
    });
  }

  // ══════════════════════════════════════════════════════════
  // SCAN BADGE
  // ══════════════════════════════════════════════════════════
  function updateScanBadge(type, data){
    var $badge = $('.cwpa-scan-card[data-type="'+type+'"] .cwpa-scan-badge');
    var issues = data && data.issues ? data.issues : [];
    var hasCrit = issues.some(function(i){ return i.severity==='critical'; });
    var hasWarn = issues.some(function(i){ return i.severity==='warning'; });
    $badge.removeClass('critical warning ok');
    if (hasCrit)      { $badge.addClass('critical').text('Critique'); }
    else if (hasWarn) { $badge.addClass('warning').text('Attention'); }
    else              { $badge.addClass('ok').text('OK'); }
  }

  // ══════════════════════════════════════════════════════════
  // CHAT
  // ══════════════════════════════════════════════════════════
  $('#cwpa-chat-send').on('click', sendChat);
  $('#cwpa-chat-input').on('keydown', function(e){
    if (e.key==='Enter' && !e.shiftKey){ e.preventDefault(); sendChat(); }
  });

  function sendChat(){
    var msg = $('#cwpa-chat-input').val().trim();
    if (!msg) return;
    appendChatMsg('user', msg);
    $('#cwpa-chat-input').val('');
    chatHistory.push({ role:'user', content:msg });

    var $typing = $('<div class="cwpa-chat-msg cwpa-chat-assistant"><div class="cwpa-chat-avatar">⬡</div><div class="cwpa-chat-bubble" style="color:#9090a8">Claude réfléchit...</div></div>');
    $('#cwpa-chat-messages').append($typing).scrollTop($('#cwpa-chat-messages')[0].scrollHeight);

    $.post(CWPA.ajax_url, { action:'cwpa_chat', nonce:CWPA.nonce, message:msg, history:chatHistory.slice(-8) }, function(res){
      $typing.remove();
      if (res.success) {
        appendChatMsg('assistant', res.data.reply);
        chatHistory.push({ role:'assistant', content:res.data.reply });
      } else {
        appendChatMsg('assistant', '⚠ Erreur: '+res.data);
      }
    }).fail(function(){ $typing.remove(); appendChatMsg('assistant','⚠ Erreur réseau.'); });
  }

  function appendChatMsg(role, text){
    var isUser = role==='user';
    var $msg = $('<div class="cwpa-chat-msg '+(isUser?'cwpa-chat-user':'cwpa-chat-assistant')+'">'+
      '<div class="cwpa-chat-avatar">'+(isUser?'👤':'⬡')+'</div>'+
      '<div class="cwpa-chat-bubble">'+escHtml(text).replace(/\n/g,'<br>')+'</div></div>');
    $('#cwpa-chat-messages').append($msg).scrollTop($('#cwpa-chat-messages')[0].scrollHeight);
  }

  // ══════════════════════════════════════════════════════════
  // HELPERS
  // ══════════════════════════════════════════════════════════
  function showLoading(show, text){
    if (show) { $('#cwpa-loading-text').text(text||'Analyse en cours...'); $('#cwpa-loading').show(); }
    else       { $('#cwpa-loading').hide(); }
  }

  function scrollTo($el){
    if (!$el.length) return;
    $('html,body').animate({ scrollTop: $el.offset().top - 30 }, 400);
  }

  function escHtml(str){
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function escAttr(str){ return escHtml(str); }

  // ══════════════════════════════════════════════════════════
  // INIT
  // ══════════════════════════════════════════════════════════
  if (CWPA.api_set) {
    loadOptimizerStatus();
    loadWebPStats();
  }

})(jQuery);
