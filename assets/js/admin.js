(function($){
  'use strict';

  // Diagnostic console — vérifie que le script charge bien
  if (typeof CWPA === 'undefined') {
    console.error('[CWPA] La variable CWPA n\'est pas définie. wp_localize_script n\'a pas fonctionné.');
    return;
  }
  console.log('[CWPA] v' + (CWPA.version || '?') + ' chargé — ajax_url: ' + CWPA.ajax_url);

  // ── Gestion nonce expiré — si un plugin de cache sert une page ancienne ──
  $(document).ajaxError(function(event, xhr){
    if (xhr.status === 403) {
      console.warn('[CWPA] Nonce expiré (403). Rechargement de la page dans 2s...');
      var $banner = $('<div style="position:fixed;top:32px;left:0;right:0;z-index:99999;background:#D4A853;color:#000;text-align:center;padding:10px;font-weight:600;">Session expirée — rechargement automatique...</div>');
      $('body').prepend($banner);
      setTimeout(function(){ location.reload(); }, 2000);
    }
  });

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

    // "Tout corriger" bar
    var fixableIds = issues.filter(function(i){ return i.auto_fixable && i.fix_id; }).map(function(i){ return i.fix_id; });
    var uniqueIds  = fixableIds.filter(function(v,i,a){ return a.indexOf(v)===i; });
    if (uniqueIds.length) {
      html += '<div class="cwpa-all-fixes-bar">';
      html += '<span class="cwpa-all-fixes-info">'+uniqueIds.length+' correction'+(uniqueIds.length>1?'s':'')+' automatique'+(uniqueIds.length>1?'s':'')+' disponible'+(uniqueIds.length>1?'s':'')+'</span>';
      html += '<button class="cwpa-btn cwpa-btn-primary cwpa-btn-apply-all" data-fixes="'+escAttr(JSON.stringify(uniqueIds))+'">⚡ Tout corriger automatiquement</button>';
      html += '<span class="cwpa-apply-all-result"></span>';
      html += '</div>';
    }

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
            fixId.indexOf('webp')>-1||fixId.indexOf('font_display')>-1||fixId.indexOf('bloat')>-1||
            fixId.indexOf('jquery_migrate')>-1||fixId.indexOf('preload')>-1||fixId.indexOf('save_data')>-1) {
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
  // TOUT CORRIGER (batch fixes)
  // ══════════════════════════════════════════════════════════
  $(document).on('click', '.cwpa-btn-apply-all', function(){
    var $btn    = $(this).prop('disabled', true);
    var fixIds  = JSON.parse($btn.data('fixes') || '[]');
    var $result = $btn.siblings('.cwpa-apply-all-result');
    if (!fixIds.length) return;

    $btn.text('Application en cours… (0/'+fixIds.length+')');
    $result.text('').removeClass('error success');

    $.post(CWPA.ajax_url, {
      action:  'cwpa_apply_all_fixes',
      nonce:   CWPA.nonce,
      fix_ids: JSON.stringify(fixIds)
    }, function(res){
      if (res.success) {
        var d = res.data;
        $btn.text('✓ '+d.applied+'/'+d.total+' correction'+(d.total>1?'s':'')+' appliquée'+(d.applied>1?'s':''));
        $result.addClass('success').text(d.applied === d.total ? 'Tout appliqué !' : d.applied+' appliquées, '+(d.total-d.applied)+' échouées');
        // Marque les boutons individuels comme corrigés
        fixIds.forEach(function(fid){
          var $fb = $('[data-fix="'+fid+'"]').not('.cwpa-btn-apply-all');
          $fb.prop('disabled', true).text('✓ Corrigé').css('opacity','0.5');
          $fb.siblings('.cwpa-fix-result').addClass('success').text('✓ Appliqué').show();
        });
        setTimeout(loadOptimizerStatus, 500);
      } else {
        $btn.prop('disabled', false).text('⚡ Tout corriger automatiquement');
        $result.addClass('error').text('⚠ '+escHtml(String(res.data)));
      }
    }).fail(function(){
      $btn.prop('disabled', false).text('⚡ Tout corriger automatiquement');
      $result.addClass('error').text('⚠ Erreur réseau');
    });
  });

  // ══════════════════════════════════════════════════════════
  // DIAGNOSTIC
  // ══════════════════════════════════════════════════════════
  $('#cwpa-run-diag').on('click', function(){
    var $btn = $(this).prop('disabled', true).text('Analyse...');
    var $res = $('#cwpa-diag-results').show().html('<div class="cwpa-loading-inline"><div class="cwpa-spinner-sm"></div> Vérification en cours...</div>');

    $.post(CWPA.ajax_url, { action:'cwpa_diagnostics', nonce:CWPA.nonce }, function(res){
      $btn.prop('disabled', false).text('🔍 Relancer');
      if (!res.success) { $res.html('<div class="cwpa-ajax-error">Erreur diagnostic</div>'); return; }
      renderDiag(res.data);
    }).fail(function(xhr){
      $btn.prop('disabled', false).text('🔍 Relancer');
      $res.html('<div class="cwpa-ajax-error">⚠ Erreur réseau HTTP ' + xhr.status + '</div>');
    });
  });

  function renderDiag(checks){
    var icons = { ok:'✓', warn:'⚠', critical:'✗', info:'ℹ' };
    var html = '<div class="cwpa-diag-list">';
    checks.forEach(function(c){
      html += '<div class="cwpa-diag-item cwpa-diag-'+c.status+'">';
      html += '<span class="cwpa-diag-icon">'+icons[c.status]+'</span>';
      html += '<div class="cwpa-diag-text">';
      html += '<span class="cwpa-diag-label">'+escHtml(c.label)+'</span>';
      if (c.message) html += '<span class="cwpa-diag-msg">'+escHtml(c.message)+'</span>';
      html += '</div></div>';
    });
    html += '</div>';
    $('#cwpa-diag-results').html(html);
  }

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
      if (!res.success) {
        $('#cwpa-pagespeed-results').html('<div class="cwpa-ajax-error">⚠ Erreur PageSpeed : ' + escHtml(res.data) + '</div>').show();
        return;
      }
      renderPageSpeed(res.data);
    }).fail(function(xhr){
      showLoading(false);
      var msg = xhr.status === 0 ? 'Serveur injoignable' : 'HTTP ' + xhr.status;
      $('#cwpa-pagespeed-results').html('<div class="cwpa-ajax-error">⚠ Erreur réseau : ' + msg + '</div>').show();
    });
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

    // Auto-appel Claude si API configurée
    if (CWPA.api_set) {
      askClaudePageSpeed(d);
    }
  }

  function askClaudePageSpeed(psData) {
    var $container = $('#cwpa-pagespeed-results');
    var $aiBlock = $('<div class="cwpa-ps-ai-section"><div class="cwpa-loading-inline"><div class="cwpa-spinner-sm"></div> <strong>⬡ Claude analyse les résultats PageSpeed…</strong></div></div>');
    $container.append($aiBlock);

    $.post(CWPA.ajax_url, {
      action:        'cwpa_pagespeed_ai',
      nonce:         CWPA.nonce,
      pagespeed_data: JSON.stringify(psData)
    }, function(res){
      if (!res.success) {
        $aiBlock.html('<div class="cwpa-ajax-error">⚠ Erreur analyse Claude : '+escHtml(res.data)+'</div>');
        return;
      }
      renderPageSpeedAI(res.data, $aiBlock);
    }).fail(function(xhr){
      $aiBlock.html('<div class="cwpa-ajax-error">⚠ Erreur réseau analyse Claude (HTTP '+xhr.status+')</div>');
    });
  }

  function renderPageSpeedAI(data, $container) {
    if (!data || !data.fixes || !data.fixes.length) {
      $container.html('<div class="cwpa-ps-ai-section"><div class="cwpa-ps-ai-header"><span class="cwpa-ps-ai-title">⬡ Claude AI</span> <span style="color:var(--cwpa-ok);font-size:13px;">✓ Aucune correction prioritaire supplémentaire détectée.</span></div></div>');
      return;
    }

    var fixableIds = data.fixes
      .filter(function(f){ return f.auto_fixable && f.fix_id; })
      .map(function(f){ return f.fix_id; })
      .filter(function(v,i,a){ return a.indexOf(v)===i; });

    var html = '<div class="cwpa-ps-ai-section">';
    html += '<div class="cwpa-ps-ai-header">';
    html += '<div class="cwpa-ps-ai-title">⬡ Recommandations Claude AI</div>';
    if (data.summary) html += '<div class="cwpa-ps-ai-summary">'+escHtml(data.summary)+'</div>';
    if (fixableIds.length) {
      html += '<div class="cwpa-ps-ai-actions">';
      html += '<button class="cwpa-btn cwpa-btn-primary cwpa-btn-apply-all" data-fixes="'+escAttr(JSON.stringify(fixableIds))+'">⚡ Tout corriger ('+fixableIds.length+' correction'+(fixableIds.length>1?'s':'')+')</button>';
      html += '<span class="cwpa-apply-all-result"></span>';
      html += '</div>';
    }
    html += '</div>';

    html += '<div class="cwpa-ps-ai-fixes">';
    data.fixes.forEach(function(fix, i){
      var canFix = fix.auto_fixable && fix.fix_id;
      var impact = fix.impact || 'medium';
      html += '<div class="cwpa-ps-ai-fix">';
      html += '<div class="cwpa-ps-ai-fix-head">';
      html += '<span class="cwpa-ps-ai-impact cwpa-impact-'+escAttr(impact)+'">'+escHtml(impact.toUpperCase())+'</span>';
      html += '<span class="cwpa-ps-ai-fix-title">'+escHtml(fix.title||'')+'</span>';
      html += '</div>';
      html += '<div class="cwpa-ps-ai-fix-desc">'+escHtml(fix.description||'')+'</div>';
      html += '<div class="cwpa-issue-actions">';
      if (canFix) {
        html += '<button class="cwpa-btn cwpa-btn-fix" data-fix="'+escAttr(fix.fix_id)+'">✓ Corriger automatiquement</button>';
        html += '<span class="cwpa-fix-result" id="ai-fix-'+i+'"></span>';
      }
      html += '</div></div>';
    });
    html += '</div></div>';

    $container.html(html);
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
    { id:'webp_serving',            label:'Servir WebP (.htaccess)',   desc:'Redirige auto vers .webp si disponible',                on:'enable_webp_serving',         off:'disable_webp_serving',        icon:'🌅' },
    { id:'webp_auto',               label:'Auto-convert WebP',         desc:'Convertit les nouvelles images à l\'upload',            on:'enable_webp_auto',            off:'disable_webp_auto',           icon:'⚙️' },
    // ── 4G / Mobile ──────────────────────────────────────────────────────────
    { id:'font_display_swap',       label:'Font-display swap',         desc:'Ajoute display=swap aux Google Fonts — évite le FOIT',  on:'enable_font_display_swap',    off:'disable_font_display_swap',   icon:'🔤' },
    { id:'remove_wp_bloat',         label:'Supprimer le bloat WP',     desc:'Retire generator, rsd_link, wlwmanifest du <head>',     on:'enable_remove_wp_bloat',      off:'disable_remove_wp_bloat',     icon:'🧹' },
    { id:'disable_jquery_migrate',  label:'Désactiver jQuery Migrate', desc:'Économise ~10 Ko — inutile sur les thèmes modernes',    on:'disable_jquery_migrate',      off:'enable_jquery_migrate',       icon:'⚡' },
    { id:'preload_key_assets',      label:'Précharger CSS/police',     desc:'<link rel="preload"> sur le CSS principal et la police', on:'enable_preload_key_assets',   off:'disable_preload_key_assets',  icon:'🚀' },
    { id:'save_data',               label:'Mode Save-Data (4G/3G)',    desc:'Allège le contenu quand le navigateur signale Save-Data',on:'enable_save_data',            off:'disable_save_data',           icon:'📡' },
  ];

  function loadOptimizerStatus(){
    console.log('[CWPA] Chargement statut optimisations...');

    // Timeout de sécurité — si AJAX ne répond pas en 12s, affiche un diagnostic
    var timeoutOptim = setTimeout(function(){
      console.error('[CWPA] Timeout optimizer_status — AJAX bloqué ou version obsolète');
      $('#cwpa-optim-grid').html(cwpaTimeoutMsg('cwpa_optimizer_status'));
    }, 12000);

    $.post(CWPA.ajax_url, { action:'cwpa_optimizer_status', nonce:CWPA.nonce })
      .done(function(res){
        clearTimeout(timeoutOptim);
        console.log('[CWPA] Réponse optimizer_status:', res);
        // WordPress retourne -1 si l'action AJAX n'est pas enregistrée (version obsolète)
        if (res === -1 || res === '-1' || res === 0) {
          $('#cwpa-optim-grid').html('<div class="cwpa-ajax-error">⚠ Action AJAX non reconnue — installez la dernière version du plugin (v'+CWPA.version+' chargé mais le serveur a peut-être une version plus ancienne).</div>');
          return;
        }
        if (!res || !res.success) {
          var err = (res && res.data) ? res.data : 'Réponse invalide (voir console F12)';
          $('#cwpa-optim-grid').html('<div class="cwpa-ajax-error">⚠ Erreur : ' + escHtml(String(err)) + '</div>');
          return;
        }
        renderOptimizer(res.data.status, res.data.cache);
      })
      .fail(function(xhr, status, error){
        clearTimeout(timeoutOptim);
        console.error('[CWPA] Échec optimizer_status:', xhr.status, status, xhr.responseText);
        var msg = xhr.status === 0 ? 'Serveur injoignable (vérifiez admin-ajax.php)' :
                  'HTTP ' + xhr.status + ' — ' + escHtml(xhr.responseText.substring(0, 150));
        $('#cwpa-optim-grid').html('<div class="cwpa-ajax-error">⚠ Erreur AJAX : ' + msg + '</div>');
      });
  }

  function renderOptimizer(status, cache){
    var conflicts = status.conflicts || {};
    var html = '<div class="cwpa-optim-cards">';
    optimDefs.forEach(function(opt){
      var active    = status[opt.id] || false;
      var conflict  = conflicts[opt.id] || null;
      var fixId     = active ? opt.off : opt.on;
      html += '<div class="cwpa-optim-card'+(active?' cwpa-optim-active':'')+(conflict?' cwpa-optim-conflict':'')+'">';
      html += '<div class="cwpa-optim-icon">'+opt.icon+'</div>';
      var modeKey = opt.id + '_mode';
      var mode = status[modeKey] || '';
      html += '<div class="cwpa-optim-info"><div class="cwpa-optim-label">'+escHtml(opt.label)+'</div>';
      html += '<div class="cwpa-optim-desc">'+escHtml(opt.desc)+'</div>';
      if (conflict) html += '<div class="cwpa-optim-conflict-badge">🔒 Géré par '+escHtml(conflict)+'</div>';
      if (!conflict && active && mode) html += '<div class="cwpa-optim-mode-badge">'+(mode==='php'?'⚡ Mode PHP (fallback)':'⚙ Mode .htaccess')+'</div>';
      html += '</div>';
      html += '<div class="cwpa-optim-actions">';
      if (conflict) {
        html += '<span class="cwpa-optim-status active" title="Activé par '+escHtml(conflict)+'">Actif ✓</span>';
      } else {
        html += '<label class="cwpa-toggle"><input type="checkbox" class="cwpa-toggle-input" data-fix="'+escAttr(fixId)+'" data-id="'+escAttr(opt.id)+'" '+(active?'checked':'')+'>'+
                '<span class="cwpa-toggle-slider"></span></label>';
        html += '<span class="cwpa-optim-status '+(active?'active':'inactive')+'">'+(active?'Actif':'Inactif')+'</span>';
      }
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
    console.log('[CWPA] Chargement stats WebP...');

    var timeoutWebP = setTimeout(function(){
      console.error('[CWPA] Timeout webp_stats — AJAX bloqué ou version obsolète');
      $('#cwpa-webp-panel').html(cwpaTimeoutMsg('cwpa_webp_stats'));
    }, 12000);

    $.post(CWPA.ajax_url, { action:'cwpa_webp_stats', nonce:CWPA.nonce })
      .done(function(res){
        clearTimeout(timeoutWebP);
        console.log('[CWPA] Réponse webp_stats:', res);
        if (res === -1 || res === '-1' || res === 0) {
          $('#cwpa-webp-panel').html('<div class="cwpa-ajax-error">⚠ Action AJAX non reconnue — installez la dernière version du plugin.</div>');
          return;
        }
        if (!res || !res.success) {
          var err = (res && res.data) ? res.data : 'Réponse invalide (voir console)';
          $('#cwpa-webp-panel').html('<div class="cwpa-ajax-error">⚠ Erreur WebP : ' + escHtml(String(err)) + '</div>');
          return;
        }
        renderWebP(res.data);
      })
      .fail(function(xhr, status, error){
        clearTimeout(timeoutWebP);
        console.error('[CWPA] Échec webp_stats:', xhr.status, status, xhr.responseText);
        var msg = xhr.status === 0 ? 'Serveur injoignable' : 'HTTP ' + xhr.status + ' — ' + escHtml(xhr.responseText.substring(0, 150));
        $('#cwpa-webp-panel').html('<div class="cwpa-ajax-error">⚠ Erreur AJAX WebP : ' + msg + '</div>');
      });
  }

  function renderWebP(s){
    var pct = s.percent || 0;
    var r   = 56; // SVG circle radius
    var circ = 2 * Math.PI * r;
    var dash = circ - (pct / 100) * circ;

    var html = '';

    if (!s.driver) {
      html += '<div class="cwpa-webp-no-driver">';
      html += '<strong>Aucun driver WebP disponible.</strong><br>';
      html += 'Pour activer la conversion, demandez à votre hébergeur d\'activer <strong>GD</strong> (avec imagewebp) ou <strong>Imagick</strong> sur votre serveur PHP.';
      html += '</div>';
      $('#cwpa-webp-panel').html(html);
      return;
    }

    // SVG ring
    var ringHtml  = '<svg viewBox="0 0 140 140" xmlns="http://www.w3.org/2000/svg">';
    ringHtml += '<defs><linearGradient id="webpGrad" x1="0%" y1="0%" x2="100%" y2="0%">';
    ringHtml += '<stop offset="0%" style="stop-color:var(--cwpa-accent)"/>';
    ringHtml += '<stop offset="100%" style="stop-color:var(--cwpa-ok)"/>';
    ringHtml += '</linearGradient></defs>';
    ringHtml += '<circle class="cwpa-webp-ring-bg" cx="70" cy="70" r="'+r+'"/>';
    ringHtml += '<circle class="cwpa-webp-ring-fill" cx="70" cy="70" r="'+r+'" ';
    ringHtml += 'stroke-dasharray="'+circ.toFixed(1)+'" stroke-dashoffset="'+dash.toFixed(1)+'"/>';
    ringHtml += '</svg>';

    var savedLabel = s.saved_kb >= 1024
      ? (s.saved_kb / 1024).toFixed(1) + ' MB'
      : s.saved_kb + ' KB';

    html += '<div class="cwpa-webp-body">';

    // Ring
    html += '<div class="cwpa-webp-ring-wrap">';
    html += ringHtml;
    html += '<div class="cwpa-webp-ring-label">';
    html += '<span class="cwpa-webp-ring-pct">'+pct+'%</span>';
    html += '<span class="cwpa-webp-ring-sub">WebP</span>';
    html += '</div></div>';

    // Info
    html += '<div class="cwpa-webp-info">';
    html += '<div class="cwpa-webp-title">'+s.total+' images</div>';
    html += '<div class="cwpa-webp-subtitle">Bibliothèque médias WordPress · Driver : '+escHtml(s.driver.toUpperCase())+'</div>';

    html += '<div class="cwpa-webp-counters">';
    html += '<div class="cwpa-webp-counter"><span class="cwpa-webp-counter-num ok">'+s.converted+'</span><span class="cwpa-webp-counter-label">Converties</span></div>';
    html += '<div class="cwpa-webp-counter"><span class="cwpa-webp-counter-num warn">'+s.pending+'</span><span class="cwpa-webp-counter-label">En attente</span></div>';
    html += '<div class="cwpa-webp-counter"><span class="cwpa-webp-counter-num saved">'+savedLabel+'</span><span class="cwpa-webp-counter-label">Économisés</span></div>';
    html += '</div>';

    html += '<div class="cwpa-webp-cta">';
    if (s.pending > 0) {
      html += '<button class="cwpa-btn cwpa-btn-primary" id="cwpa-webp-btn">⚡ Démarrer la compression ('+s.pending+' images)</button>';
    } else if (s.total > 0) {
      html += '<div class="cwpa-webp-done"><span class="cwpa-webp-done-icon">✓</span> Toutes les images sont déjà converties en WebP !</div>';
    } else {
      html += '<div style="color:var(--cwpa-text2);font-size:13px;">Aucune image JPEG/PNG/GIF trouvée dans la bibliothèque.</div>';
    }
    html += '</div>';
    html += '</div>'; // .cwpa-webp-info

    html += '</div>'; // .cwpa-webp-body

    // Progress bar (hidden, shown during conversion)
    html += '<div class="cwpa-webp-progress" id="cwpa-webp-progress">';
    html += '<div class="cwpa-webp-progress-track"><div class="cwpa-webp-progress-bar" id="cwpa-webp-bar"></div></div>';
    html += '<div class="cwpa-webp-progress-log" id="cwpa-webp-log"></div>';
    html += '</div>';

    $('#cwpa-webp-panel').html(html);
  }

  $(document).on('click', '#cwpa-webp-btn', function(){
    $(this).prop('disabled', true).text('Compression en cours...');
    $('#cwpa-webp-progress').show();
    processBatch(0);
  });

  function processBatch(offset){
    $.post(CWPA.ajax_url, { action:'cwpa_webp_convert', nonce:CWPA.nonce, offset:offset }, function(res){
      if (!res.success) {
        $('#cwpa-webp-log').text('Erreur : '+(res.data||'inconnue'));
        return;
      }
      var d     = res.data;
      var done  = d.done_so_far || offset;
      var total = d.total || 1;
      var pct   = Math.min(100, Math.round((done / total) * 100));

      $('#cwpa-webp-bar').css('width', pct+'%');
      $('#cwpa-webp-log').text(done+' / '+total+' traitées — '+pct+'%'+(d.errors && d.errors.length ? ' · Erreurs : '+d.errors.join(', ') : ''));

      if (d.has_more) {
        setTimeout(function(){ processBatch(d.next_offset); }, 200);
      } else {
        $('#cwpa-webp-log').text('✓ Terminé — '+done+' images traitées.');
        setTimeout(function(){ loadWebPStats(); }, 800);
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

  function cwpaTimeoutMsg(action){
    return '<div class="cwpa-ajax-error" style="line-height:1.7">'
      + '⚠ <strong>Aucune réponse du serveur</strong> pour <code>'+escHtml(action)+'</code>.<br>'
      + 'Causes possibles :<br>'
      + '• Plugin pas à jour — <a href="update-core.php" style="color:var(--cwpa-accent)">installer la dernière version</a><br>'
      + '• Un plugin de sécurité bloque <code>admin-ajax.php</code> (Wordfence, iThemes…)<br>'
      + '• LiteSpeed Cache ou un plugin de cache sert une page avec un nonce expiré<br>'
      + 'Ouvrez F12 → Console et cherchez les logs <code>[CWPA]</code> pour plus de détails.'
      + '</div>';
  }

  function escAttr(str){ return escHtml(str); }

  // ══════════════════════════════════════════════════════════
  // LCP
  // ══════════════════════════════════════════════════════════
  $('#cwpa-lcp-toggle').on('change', function(){
    var enabled = $(this).is(':checked');
    $('#cwpa-lcp-settings').toggle(enabled);
    saveLCP(enabled);
  });

  $('#cwpa-lcp-save').on('click', function(){
    saveLCP($('#cwpa-lcp-toggle').is(':checked'));
  });

  function saveLCP(enabled) {
    var $res = $('#cwpa-lcp-result');
    $.post(CWPA.ajax_url, {
      action:             'cwpa_lcp_save',
      nonce:              CWPA.nonce,
      enabled:            enabled ? 1 : 0,
      manual_url:         $('#cwpa-lcp-url').val().trim(),
      preconnect_domains: $('#cwpa-lcp-domains').val().trim()
    }, function(res){
      $res.text(res.success ? '✓ Enregistré' : '⚠ '+escHtml(res.data)).css('color', res.success ? 'var(--cwpa-ok)' : 'var(--cwpa-critical)');
      setTimeout(function(){ $res.text(''); }, 3000);
    });
  }

  // ══════════════════════════════════════════════════════════
  // SSH
  // ══════════════════════════════════════════════════════════

  // Auth mode switch
  $('#cwpa-ssh-auth').on('change', function(){
    var isKey = $(this).val() === 'key';
    $('#cwpa-ssh-auth-password').toggle(!isKey);
    $('#cwpa-ssh-auth-key').toggle(isKey);
  });

  // Save SSH settings
  $('#cwpa-ssh-save').on('click', function(){
    var $btn = $(this).prop('disabled', true).text('Enregistrement...');
    var $res = $('#cwpa-ssh-result');
    $.post(CWPA.ajax_url, {
      action:   'cwpa_ssh_save',
      nonce:    CWPA.nonce,
      host:     $('#cwpa-ssh-host').val().trim(),
      port:     $('#cwpa-ssh-port').val() || 22,
      user:     $('#cwpa-ssh-user').val().trim(),
      auth:     $('#cwpa-ssh-auth').val(),
      password: $('#cwpa-ssh-password').val(),
      privkey:  $('#cwpa-ssh-privkey').val()
    }, function(res){
      $btn.prop('disabled', false).text('Enregistrer');
      $res.text(res.success ? '✓ Sauvegardé' : '⚠ '+escHtml(res.data))
          .css('color', res.success ? 'var(--cwpa-ok)' : 'var(--cwpa-critical)');
    });
  });

  // Test SSH connection
  $('#cwpa-ssh-test').on('click', function(){
    var $btn = $(this).prop('disabled', true).text('Connexion...');
    var $res = $('#cwpa-ssh-result');
    $res.text('').css('color','');
    $.post(CWPA.ajax_url, { action:'cwpa_ssh_test', nonce:CWPA.nonce }, function(res){
      $btn.prop('disabled', false).text('🔌 Tester la connexion');
      if (res.success) {
        $res.text('✓ '+res.data.message).css('color','var(--cwpa-ok)');
        if (res.data.output) showSSHOutput('Infos serveur', res.data.output);
      } else {
        $res.text('✗ '+escHtml(res.data)).css('color','var(--cwpa-critical)');
      }
    }).fail(function(xhr){
      $btn.prop('disabled', false).text('🔌 Tester la connexion');
      $res.text('✗ Erreur réseau HTTP '+xhr.status).css('color','var(--cwpa-critical)');
    });
  });

  // Render SSH action buttons
  function renderSSHActions() {
    if (!CWPA.ssh_actions) return;
    var html = '';
    $.each(CWPA.ssh_actions, function(id, a){
      var isWrite = a.write;
      html += '<button class="cwpa-btn cwpa-ssh-action-btn '+(isWrite?'cwpa-btn-primary':'cwpa-btn-ghost')+'" data-action="'+escAttr(id)+'">';
      html += (isWrite ? '⚙ ' : '') + escHtml(a.label) + '</button>';
    });
    $('#cwpa-ssh-btn-grid').html(html);
  }

  $(document).on('click', '.cwpa-ssh-action-btn', function(){
    var $btn      = $(this).prop('disabled', true).addClass('cwpa-btn-loading');
    var actionId  = $btn.data('action');
    var label     = $btn.text().trim();

    $.post(CWPA.ajax_url, { action:'cwpa_ssh_action', nonce:CWPA.nonce, action_id:actionId }, function(res){
      $btn.prop('disabled', false).removeClass('cwpa-btn-loading');
      if (res.success) {
        showSSHOutput(escHtml(res.data.label), res.data.output);
      } else {
        showSSHOutput('Erreur — '+escHtml(label), res.data);
      }
    }).fail(function(xhr){
      $btn.prop('disabled', false).removeClass('cwpa-btn-loading');
      showSSHOutput('Erreur réseau', 'HTTP '+xhr.status);
    });
  });

  function showSSHOutput(label, output) {
    $('#cwpa-ssh-output-label').text(label);
    $('#cwpa-ssh-output').text(output || '(pas de sortie)');
    $('#cwpa-ssh-output-wrap').show();
  }

  $('#cwpa-ssh-output-close').on('click', function(){
    $('#cwpa-ssh-output-wrap').hide();
  });

  // ══════════════════════════════════════════════════════════
  // INIT — optimizer + WebP sont indépendants de l'API Claude
  // ══════════════════════════════════════════════════════════
  loadOptimizerStatus();
  loadWebPStats();
  renderSSHActions();

})(jQuery);
