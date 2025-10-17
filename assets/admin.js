(function ($) {
  'use strict';

  // Secret "Change" toggle (existing)
  $(document).on('click', '.wcvec-secret-toggle', function (e) {
    e.preventDefault();
    var target = $(this).data('target');
    var $input = $(target);
    var $view = $(this).closest('.wcvec-secret').find('.wcvec-secret-view');
    if (!$input.length) return;
    $view.hide();
    $input.show().focus();
  });

  // --- Fields: search filter ---
  $('#wcvec-fields-search').on('input', function () {
    var q = ($(this).val() || '').toString().toLowerCase();
    $('.wcvec-field-item, .wcvec-acf-row').each(function () {
      var key = ($(this).attr('data-key') || '').toLowerCase();
      var label = ($(this).attr('data-label') || '').toLowerCase();
      var show = !q || key.indexOf(q) !== -1 || label.indexOf(q) !== -1;
      $(this).toggle(show);
    });
  });

  // --- Fields: select essentials ---
  $('#wcvec-select-essentials').on('click', function () {
    $('.wcvec-field-group[data-group="core"] .wcvec-field-item[data-essential="1"] input[type="checkbox"]').prop('checked', true);
    $('.wcvec-field-group[data-group="tax"] .wcvec-field-item[data-essential="1"] input[type="checkbox"]').prop('checked', true);
  });

  // --- Meta repeater ---
  function renumberMetaRows() {
    $('#wcvec-meta-table tbody tr.wcvec-meta-row').each(function (i) {
      $(this).find('input[name^="wcvec_meta_key"]').attr('name', 'wcvec_meta_key[' + i + ']');
      $(this).find('select[name^="wcvec_meta_mode"]').attr('name', 'wcvec_meta_mode[' + i + ']');
    });
  }

  $('#wcvec-meta-add-row').on('click', function (e) {
    e.preventDefault();
    var tmpl = $('#wcvec-meta-row-template').html();
    var $tbody = $('#wcvec-meta-table tbody');
    var nextIndex = $tbody.find('tr.wcvec-meta-row').length;
    tmpl = tmpl.replace(/__INDEX__/g, nextIndex);
    $tbody.append(tmpl);
    renumberMetaRows();
  });

  $(document).on('click', '.wcvec-meta-remove-row', function (e) {
    e.preventDefault();
    $(this).closest('tr.wcvec-meta-row').remove();
    renumberMetaRows();
  });

    // --- Helpers ---
  function debounce(fn, wait) {
    var t;
    return function () {
      var ctx = this, args = arguments;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, wait);
    };
  }

  function collectSelection() {
    var core = $('input[name="wcvec_core[]"]:checked').map(function(){return $(this).val();}).get();
    var tax  = $('input[name="wcvec_tax[]"]:checked').map(function(){return $(this).val();}).get();
    var attributes = $('input[name="wcvec_attributes[]"]:checked').map(function(){return $(this).val();}).get();
    var seo  = $('input[name="wcvec_seo[]"]:checked').map(function(){return $(this).val();}).get();

    var meta = {};
    $('#wcvec-meta-table tbody tr.wcvec-meta-row').each(function(){
      var key  = $(this).find('input[name^="wcvec_meta_key"]').val() || '';
      var mode = $(this).find('select[name^="wcvec_meta_mode"]').val() || 'text';
      key = key.trim();
      if (key) { meta[key] = (mode === 'json' ? 'json' : 'text'); }
    });

    var acf = [];
    $('.wcvec-acf-table tr.wcvec-acf-row').each(function(){
      var $row = $(this);
      var selected = $row.find('input[type="checkbox"][name*="[selected]"]').is(':checked');
      if (!selected) return;
      var field_key = $row.attr('data-key') || '';
      var name  = $row.find('input[name*="[name]"]').val() || '';
      var label = $row.find('input[name*="[label]"]').val() || '';
      var type  = $row.find('input[name*="[type]"]').val() || 'text';
      var group_key = $row.find('input[name*="[group_key]"]').val() || '';
      var mode  = $row.find('select[name*="[mode]"]').val() || 'text';
      acf.push({
        group_key: group_key,
        field_key: field_key,
        name: name,
        label: label,
        type: type,
        mode: (mode === 'json' ? 'json' : 'text')
      });
    });

    var flags = { show_private_meta: $('#wcvec_show_private_meta').is(':checked') };

    return {
      core: core,
      tax: tax,
      attributes: attributes,
      seo: seo,
      meta: meta,
      acf: acf,
      flags: flags
    };
  }

  function preview(productId) {
    if (!productId) return;

    var $status = $('#wcvec-preview-status');
    var $text = $('#wcvec-preview-text');

    $status.removeClass('wcvec-ok wcvec-bad').text((WCVecAdmin && WCVecAdmin.i18n) ? WCVecAdmin.i18n.previewing : 'Generating preview…');
    $text.text('');

    var sel = collectSelection();

    $.ajax({
      url: (WCVecAdmin && WCVecAdmin.ajaxUrl) ? WCVecAdmin.ajaxUrl : ajaxurl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'wcvec_fields_preview',
        _nonce: WCVecAdmin.nonceFieldsPreview,
        product_id: productId,
        selection: JSON.stringify(sel)
      }
    }).done(function(res){
      if (res && res.success) {
        $status.addClass('wcvec-ok').removeClass('wcvec-bad').text('OK');
        $text.text(res.data && res.data.text ? res.data.text : '');
      } else {
        var msg = (res && res.data && res.data.message) ? res.data.message : 'Preview request failed.';
        $status.addClass('wcvec-bad').removeClass('wcvec-ok').text(msg);
      }
    }).fail(function(xhr){
      $status.addClass('wcvec-bad').removeClass('wcvec-ok').text('Preview request failed: ' + xhr.status + ' ' + xhr.statusText);
    });
  }

  var debouncedPreview = debounce(function(){
    var pid = $('#wcvec-preview-product-id').val();
    if (pid) preview(pid);
  }, 400);

  // Trigger preview on changes
  $(document).on('change', '#wcvec-fields-form input, #wcvec-fields-form select, #wcvec-fields-form textarea', debouncedPreview);

  $('#wcvec-preview-refresh').on('click', function(e){
    e.preventDefault();
    var pid = $('#wcvec-preview-product-id').val();
    if (pid) preview(pid);
  });

  // --- Autocomplete for product search ---
  $('#wcvec-preview-product-search').autocomplete({
    minLength: 2,
    delay: 200,
    source: function(request, response){
      $.ajax({
        url: (WCVecAdmin && WCVecAdmin.ajaxUrl) ? WCVecAdmin.ajaxUrl : ajaxurl,
        method: 'POST',
        dataType: 'json',
        data: {
          action: 'wcvec_search_products',
          _nonce: WCVecAdmin.nonceSearchProducts,
          q: request.term,
          limit: 20
        },
        success: function(res){
          if (res && res.success && res.data && res.data.results) {
            response($.map(res.data.results, function(item){
              return {
                label: item.text,
                value: item.text,
                id: item.id
              };
            }));
          } else {
            response([]);
          }
        },
        error: function(){ response([]); }
      });
    },
    select: function(event, ui){
      $('#wcvec-preview-product-id').val(ui.item.id);
      // Trigger a preview immediately on selection
      preview(ui.item.id);
    }
  });

  function renumberRows($table) {
    // Renumber inputs by row order to keep indices sequential
    $table.find('tbody tr.wcvec-about-row').each(function (i) {
      $(this).find('input, textarea').each(function () {
        var name = $(this).attr('name');
        if (!name) return;
        // Replace [<num>] with [i]
        name = name.replace(/\[\d+\]/, '[' + i + ']');
        $(this).attr('name', name);
      });
    });
  }

  $('#wcvec-about-add-row').on('click', function (e) {
    e.preventDefault();
    var $table = $('#wcvec-about-table');
    var $tbody = $table.find('tbody');
    var tmpl = $('#wcvec-about-row-template').html();
    var nextIndex = $tbody.find('tr.wcvec-about-row').length;
    tmpl = tmpl.replace(/__INDEX__/g, nextIndex);
    $tbody.append(tmpl);
    renumberRows($table);
  });

  $(document).on('click', '.wcvec-about-remove-row', function (e) {
    e.preventDefault();
    var $table = $('#wcvec-about-table');
    $(this).closest('tr.wcvec-about-row').remove();
    renumberRows($table);
  });

  // Drag & drop reordering
  $('#wcvec-about-table tbody').sortable({
    handle: '.col-drag',
    items: 'tr.wcvec-about-row',
    helper: function (e, tr) {
      var $originals = tr.children();
      var $helper = tr.clone();
      $helper.children().each(function (index) {
        // Set helper cell sizes to match original
        $(this).width($originals.eq(index).width());
      });
      return $helper;
    },
    stop: function () {
      renumberRows($('#wcvec-about-table'));
    }
  });

  function postAjax(action, nonce, onDone) {
    $.ajax({
      url: (window.WCVecAdmin && WCVecAdmin.ajaxUrl) ? WCVecAdmin.ajaxUrl : ajaxurl,
      method: 'POST',
      data: {
        action: action,
        _nonce: nonce
      },
      dataType: 'json'
    }).done(function (res) {
      if (typeof onDone === 'function') onDone(res);
    }).fail(function (xhr) {
      if (typeof onDone === 'function') {
        onDone({
          success: false,
          data: { message: 'Request failed: ' + xhr.status + ' ' + xhr.statusText }
        });
      }
    });
  }

  $('#wcvec-validate-openai').on('click', function () {
    var $out = $('#wcvec-validate-openai-result');
    $out.removeClass('wcvec-ok wcvec-bad').text((WCVecAdmin && WCVecAdmin.i18n) ? WCVecAdmin.i18n.validating : 'Validating…');

    postAjax('wcvec_validate_openai', WCVecAdmin.nonceValidateOpenAI, function (res) {
      if (res && res.success) {
        $out.addClass('wcvec-ok').removeClass('wcvec-bad').text(res.data && res.data.message ? res.data.message : 'OK');
      } else {
        var msg = res && res.data && res.data.message ? res.data.message : 'Validation failed.';
        $out.addClass('wcvec-bad').removeClass('wcvec-ok').text(msg);
      }
    });
  });

  $('#wcvec-validate-pinecone').on('click', function () {
    var $out = $('#wcvec-validate-pinecone-result');
    $out.removeClass('wcvec-ok wcvec-bad').text((WCVecAdmin && WCVecAdmin.i18n) ? WCVecAdmin.i18n.validating : 'Validating…');

    postAjax('wcvec_validate_pinecone', WCVecAdmin.nonceValidatePinecone, function (res) {
      if (res && res.success) {
        $out.addClass('wcvec-ok').removeClass('wcvec-bad').text(res.data && res.data.message ? res.data.message : 'OK');
      } else {
        var msg = res && res.data && res.data.message ? res.data.message : 'Validation failed.';
        $out.addClass('wcvec-bad').removeClass('wcvec-ok').text(msg);
      }
    });
  });

    function setSampleStatus(msg, ok) {
    var $s = $('#wcvec-sample-status');
    $s.removeClass('wcvec-ok wcvec-bad').text(msg || '');
    if (ok === true) $s.addClass('wcvec-ok');
    if (ok === false) $s.addClass('wcvec-bad');
  }
  function setSampleDetails(obj) {
    var $d = $('#wcvec-sample-details');
    if (!obj) { $d.hide().text(''); return; }
    $d.show().text(JSON.stringify(obj, null, 2));
  }

  function resolveProductIdForSample(cb) {
    var pid = parseInt($('#wcvec-sample-product-id').val(), 10);
    if (pid > 0) return cb(pid);
    // If blank, let server pick first published — just continue with 0
    cb(0);
  }

  $('#wcvec-sample-use-first').on('click', function(e){
    e.preventDefault();
    // Set to 0 and let server pick first published product
    $('#wcvec-sample-product-id').val('');
    setSampleStatus((WCVecAdmin && WCVecAdmin.i18n) ? WCVecAdmin.i18n.working : 'Working…');
    setSampleDetails(null);

    // Fire a light upsert call with target='openai' but it will only build payloads and create store if needed
    // We won't auto-run here to avoid unintended writes. Just update status hint.
    setSampleStatus('<?php echo esc_js(__('Product will be auto-selected (first published) when you click Upsert/Delete.', 'wc-vector-indexing')); ?>');
  });

  $('.wcvec-sample-upsert').on('click', function(e){
    e.preventDefault();
    var $btns = $('.wcvec-sample-upsert, .wcvec-sample-delete').prop('disabled', true);
    setSampleStatus((WCVecAdmin && WCVecAdmin.i18n) ? WCVecAdmin.i18n.working : 'Working…');
    setSampleDetails(null);

    var target = $(this).data('target') || '';
    resolveProductIdForSample(function(pid){
      $.ajax({
        url: (WCVecAdmin && WCVecAdmin.ajaxUrl) ? WCVecAdmin.ajaxUrl : ajaxurl,
        method: 'POST',
        dataType: 'json',
        data: {
          action: 'wcvec_sample_upsert',
          _nonce: WCVecAdmin.nonceSampleUpsert,
          target: target,
          product_id: pid
        }
      }).done(function(res){
        if (res && res.success) {
          setSampleStatus(res.data && res.data.message ? res.data.message : 'OK', true);
          setSampleDetails(res.data || {});
        } else {
          var msg = (res && res.data && res.data.message) ? res.data.message : 'Upsert failed.';
          setSampleStatus(msg, false);
          setSampleDetails(res && res.data ? res.data : null);
        }
      }).fail(function(xhr){
        setSampleStatus('Upsert failed: ' + xhr.status + ' ' + xhr.statusText, false);
      }).always(function(){
        $btns.prop('disabled', false);
      });
    });
  });

  $('.wcvec-sample-delete').on('click', function(e){
    e.preventDefault();
    var $btns = $('.wcvec-sample-upsert, .wcvec-sample-delete').prop('disabled', true);
    setSampleStatus((WCVecAdmin && WCVecAdmin.i18n) ? WCVecAdmin.i18n.working : 'Working…');
    setSampleDetails(null);

    var target = $(this).data('target') || '';
    resolveProductIdForSample(function(pid){
      if (!pid) {
        setSampleStatus('<?php echo esc_js(__('Please enter a Product ID to delete from the vector store.', 'wc-vector-indexing')); ?>', false);
        $btns.prop('disabled', false);
        return;
      }
      $.ajax({
        url: (WCVecAdmin && WCVecAdmin.ajaxUrl) ? WCVecAdmin.ajaxUrl : ajaxurl,
        method: 'POST',
        dataType: 'json',
        data: {
          action: 'wcvec_sample_delete',
          _nonce: WCVecAdmin.nonceSampleDelete,
          target: target,
          product_id: pid
        }
      }).done(function(res){
        if (res && res.success) {
          setSampleStatus(res.data && res.data.message ? res.data.message : 'OK', true);
          setSampleDetails(res.data || {});
        } else {
          var msg = (res && res.data && res.data.message) ? res.data.message : 'Delete failed.';
          setSampleStatus(msg, false);
          setSampleDetails(res && res.data ? res.data : null);
        }
      }).fail(function(xhr){
        setSampleStatus('Delete failed: ' + xhr.status + ' ' + xhr.statusText, false);
      }).always(function(){
        $btns.prop('disabled', false);
      });
    });
  });

})(jQuery);
