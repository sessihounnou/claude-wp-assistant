(function($){
  'use strict';

  var chatHistory = [];
  var lastScanData = {};

  // ── API Key ──────────────────────────────────────────────
  $('#cwpa-save-key').on('click', function(){
    var key = $('#cwpa-api-key-input').val().trim();
    if (!key || !key.startsWith('sk-ant-')) {
      $('#cwpa-key-feedback').html('<span style="color:#ff4d6d">⚠ Clé invalide. Elle doit commencer par sk-ant-</span>');
      return;
    }
    var $btn = $(this).prop('disabled', true).text('Enregistrement...');
    $.post(CWPA.ajax_url, {
      action: 'cwpa_save_key',
      nonce:   CWPA.nonce,
      api_key: key
    }, function(res){
      if (res.success) {
        $('#cwpa-key-feedback').html('<span style="color:#4dde94">✓ Clé enregistrée ! Rechargement...</span>');
        setTimeout(function(){ location.reload(); }, 1000);
      } else {
        $('#cwpa-key-feedback').html('<span style="color:#ff4d6d">Erreur: ' + res.data + '</span>');
        $btn.prop('disabled', false).text('Enregistrer la clé');
      }
    });
  });

  // ── Individual Scan ──────────────────────────────────────
  $('.cwpa-btn-scan').on('click', function(){
    var $card = $(this).closest('.cwpa-scan-card');
    var type  = $card.data('type');
    runScan(type);
  });

  // ── Full Scan ────────────────────────────────────────────
  var scanTypes = ['php_errors','performance','plugins','security','seo'];
  var scanLabels = {
    php_errors: 'Erreurs PHP',
    performance: 'Performance',
    plugins: 'Plugins',
    security: 'Sécurité',
    seo: 'SEO'
  };

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
    showLoading(true, 'Analyse ' + scanLabels[type] + ' (' + (index+1) + '/' + scanTypes.length + ')...');
    $.post(CWPA.ajax_url, {
      action: 'cwpa_scan',
      nonce: CWPA.nonce,
      scan_type: type
    }, function(res){
      if (res.success) {
        results.push({ type: type, data: res.data.data });
        updateScanBadge(type, res.data.data);
      }
      runAllScans(index + 1, results);
    }).fail(function(){
      runAllScans(index + 1, results);
    });
  }

  function runScan(type){
    showLoading(true, 'Claude analyse ' + scanLabels[type] + '...');
    $.post(CWPA.ajax_url, {
      action: 'cwpa_scan',
      nonce:  CWPA.nonce,
      scan_type: type
    }, function(res){
      showLoading(false);
      if (!res.success) {
        alert('Erreur: ' + res.data);
        return;
      }
      var data = res.data.data;
      lastScanData[type] = data;
      updateScanBadge(type, data);
      renderResults(type, data);
    }).fail(function(){
      showLoading(false);
      alert('Erreur réseau. Vérifiez votre connexion.');
    });
  }

  // ── Render Results ───────────────────────────────────────
  function renderResults(type, data){
    var $section = $('#cwpa-results-section').show();
    $('#cwpa-results-type').text('— ' + scanLabels[type]);
    $('#cwpa-results-container').html(buildResultsHTML(type, data));
    $('html, body').animate({ scrollTop: $section.offset().top - 30 }, 400);
  }

  function renderAllResults(results){
    var html = '';
    results.forEach(function(r){
      html += '<div style="margin-bottom:24px"><div class="cwpa-section-title" style="margin-bottom:12px">'+scanLabels[r.type]+'</div>';
      html += buildResultsHTML(r.type, r.data);
      html += '</div>';
    });
    $('#cwpa-results-section').show();
    $('#cwpa-results-type').text('— Complet');
    $('#cwpa-results-container').html(html);
    $('html, body').animate({ scrollTop: $('#cwpa-results-section').offset().top - 30 }, 400);
  }

  function buildResultsHTML(type, data){
    if (!data) return '<div class="cwpa-card"><p style="color:#9090a8">Aucune donnée reçue.</p></div>';

    var score   = data.score || 0;
    var summary = data.summary || '';
    var issues  = data.issues || [];
    var priority= data.priority_action || '';

    var scoreClass = score >= 80 ? 'good' : score >= 50 ? 'medium' : 'bad';

    var html = '<div class="cwpa-results-header">';
    html += '<div class="cwpa-score">';
    html += '<div class="cwpa-score-circle '+scoreClass+'">'+score+'</div>';
    html += '<div class="cwpa-score-info"><h3>Score '+scanLabels[type]+'</h3><p>'+escHtml(summary)+'</p></div>';
    html += '</div>';
    if (priority) {
      html += '<div class="cwpa-priority"><strong>⚡ Action prioritaire</strong>'+escHtml(priority)+'</div>';
    }
    html += '</div>';

    if (!issues.length) {
      html += '<div class="cwpa-issues-list"><div class="cwpa-issue"><span class="cwpa-issue-sev info"></span><div class="cwpa-issue-body"><p class="cwpa-issue-title" style="color:#4dde94">✓ Aucun problème détecté</p></div></div></div>';
      return html;
    }

    // Sort: critical first
    issues.sort(function(a,b){
      var order = {critical:0, warning:1, info:2};
      return (order[a.severity]||2) - (order[b.severity]||2);
    });

    html += '<div class="cwpa-issues-list">';
    issues.forEach(function(issue, i){
      var sev   = issue.severity || 'info';
      var fixId = issue.fix_id || '';
      var canFix= issue.auto_fixable && fixId;

      html += '<div class="cwpa-issue">';
      html += '<span class="cwpa-issue-sev '+sev+'"></span>';
      html += '<div class="cwpa-issue-body">';
      html += '<div class="cwpa-issue-title">'+escHtml(issue.title||'')+'</div>';
      html += '<div class="cwpa-issue-desc">'+escHtml(issue.description||'')+'</div>';
      if (issue.fix_suggestion) {
        html += '<div class="cwpa-issue-fix">💡 '+escHtml(issue.fix_suggestion)+'</div>';
      }
      html += '<div class="cwpa-issue-actions">';
      if (canFix) {
        html += '<button class="cwpa-btn cwpa-btn-fix" data-fix="'+escHtml(fixId)+'">✓ Corriger automatiquement</button>';
        html += '<span class="cwpa-fix-result" id="fix-result-'+i+'"></span>';
      }
      html += '</div>';
      html += '</div></div>';
    });
    html += '</div>';
    return html;
  }

  // ── Fix ──────────────────────────────────────────────────
  $(document).on('click', '.cwpa-btn-fix', function(){
    var $btn  = $(this).prop('disabled', true).text('En cours...');
    var fixId = $btn.data('fix');
    var $res  = $btn.siblings('.cwpa-fix-result');

    $.post(CWPA.ajax_url, {
      action: 'cwpa_fix',
      nonce:  CWPA.nonce,
      fix_id: fixId
    }, function(res){
      if (res.success && res.data.success) {
        $res.removeClass('error').addClass('success').text('✓ ' + res.data.message).show();
        $btn.text('✓ Corrigé').css('opacity','0.5');
      } else {
        var msg = (res.data && res.data.message) ? res.data.message : res.data;
        $res.removeClass('success').addClass('error').text('✗ ' + msg).show();
        $btn.prop('disabled', false).text('Corriger automatiquement');
      }
    }).fail(function(){
      $res.removeClass('success').addClass('error').text('✗ Erreur réseau').show();
      $btn.prop('disabled', false).text('Corriger automatiquement');
    });
  });

  // ── Badge Update ─────────────────────────────────────────
  function updateScanBadge(type, data){
    var $badge = $('.cwpa-scan-card[data-type="'+type+'"] .cwpa-scan-badge');
    var issues = data && data.issues ? data.issues : [];
    var hasCrit = issues.some(function(i){ return i.severity === 'critical'; });
    var hasWarn = issues.some(function(i){ return i.severity === 'warning'; });
    $badge.removeClass('critical warning ok');
    if (hasCrit)        { $badge.addClass('critical').text('Critique'); }
    else if (hasWarn)   { $badge.addClass('warning').text('Attention'); }
    else                { $badge.addClass('ok').text('OK'); }
  }

  // ── Chat ─────────────────────────────────────────────────
  $('#cwpa-chat-send').on('click', sendChat);
  $('#cwpa-chat-input').on('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); }
  });

  function sendChat(){
    var msg = $('#cwpa-chat-input').val().trim();
    if (!msg) return;

    appendChatMsg('user', msg);
    $('#cwpa-chat-input').val('');
    chatHistory.push({ role: 'user', content: msg });

    var $typing = $('<div class="cwpa-chat-msg cwpa-chat-assistant"><div class="cwpa-chat-avatar">⬡</div><div class="cwpa-chat-bubble" style="color:#9090a8">Claude réfléchit...</div></div>');
    $('#cwpa-chat-messages').append($typing).scrollTop($('#cwpa-chat-messages')[0].scrollHeight);

    $.post(CWPA.ajax_url, {
      action: 'cwpa_chat',
      nonce:  CWPA.nonce,
      message: msg,
      history: chatHistory.slice(-8)
    }, function(res){
      $typing.remove();
      if (res.success) {
        appendChatMsg('assistant', res.data.reply);
        chatHistory.push({ role: 'assistant', content: res.data.reply });
      } else {
        appendChatMsg('assistant', '⚠ Erreur: ' + res.data);
      }
    }).fail(function(){
      $typing.remove();
      appendChatMsg('assistant', '⚠ Erreur réseau.');
    });
  }

  function appendChatMsg(role, text){
    var isUser = role === 'user';
    var avatar = isUser ? '👤' : '⬡';
    var cls    = isUser ? 'cwpa-chat-user' : 'cwpa-chat-assistant';
    var $msg = $('<div class="cwpa-chat-msg '+cls+'"><div class="cwpa-chat-avatar">'+avatar+'</div><div class="cwpa-chat-bubble">'+escHtml(text).replace(/\n/g,'<br>')+'</div></div>');
    $('#cwpa-chat-messages').append($msg).scrollTop($('#cwpa-chat-messages')[0].scrollHeight);
  }

  // ── Helpers ──────────────────────────────────────────────
  function showLoading(show, text){
    if (show) {
      $('#cwpa-loading-text').text(text || 'Claude analyse votre site...');
      $('#cwpa-loading').show();
    } else {
      $('#cwpa-loading').hide();
    }
  }

  function escHtml(str){
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

})(jQuery);
