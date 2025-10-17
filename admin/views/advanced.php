<?php
/** @var string $nonce_save */
/** @var int    $max_concurrent */
/** @var int    $batch_upsert_size */
/** @var bool   $include_drafts_priv */
/** @var string $variation_strategy */
/** @var bool   $manual_include_vars */
/** @var int    $retention_days */
/** @var bool   $allow_dim_override */
/** @var string $nonce_purge */
/** @var int    $site_id */
?>
<div class="wrap">
  <h1><?php esc_html_e('Advanced Settings', 'wc-vector-indexing'); ?></h1>

  <?php if (!empty($_GET['notice']) && $_GET['notice']==='saved'): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'wc-vector-indexing'); ?></p></div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="wcvec_advanced_save" />
    <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce_save); ?>" />

    <h2><?php esc_html_e('Performance', 'wc-vector-indexing'); ?></h2>
    <table class="form-table" role="presentation">
      <tbody>
        <tr>
          <th scope="row"><?php esc_html_e('Max concurrent index jobs', 'wc-vector-indexing'); ?></th>
          <td>
            <input type="number" min="1" max="10" name="max_concurrent" value="<?php echo esc_attr((string)$max_concurrent); ?>" />
            <p class="description"><?php esc_html_e('Upper bound on simultaneous indexing jobs (Action Scheduler).', 'wc-vector-indexing'); ?></p>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e('Batch upsert size', 'wc-vector-indexing'); ?></th>
          <td>
            <input type="number" min="10" max="500" name="batch_upsert_size" value="<?php echo esc_attr((string)$batch_upsert_size); ?>" />
            <p class="description"><?php esc_html_e('Vectors per upsert call for adapters. Lower this if your host or APIs are memory/timeout sensitive.', 'wc-vector-indexing'); ?></p>
          </td>
        </tr>
      </tbody>
    </table>

    <h2><?php esc_html_e('Content scope', 'wc-vector-indexing'); ?></h2>
    <table class="form-table" role="presentation">
      <tbody>
        <tr>
          <th scope="row"><?php esc_html_e('Include drafts/private', 'wc-vector-indexing'); ?></th>
          <td>
            <label>
              <input type="checkbox" name="include_drafts_priv" <?php checked($include_drafts_priv, true); ?> />
              <?php esc_html_e('Index products that are draft or private (in addition to published).', 'wc-vector-indexing'); ?>
            </label>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e('Variation strategy', 'wc-vector-indexing'); ?></th>
          <td>
            <label><input type="radio" name="variation_strategy" value="separate" <?php checked($variation_strategy, 'separate'); ?> />
              <?php esc_html_e('Separate: index parent and each variation (default)', 'wc-vector-indexing'); ?></label><br />
            <label><input type="radio" name="variation_strategy" value="collapse" <?php checked($variation_strategy, 'collapse'); ?> />
              <?php esc_html_e('Collapse: index parent only and roll up variation data (P8.2)', 'wc-vector-indexing'); ?></label><br />
            <label><input type="radio" name="variation_strategy" value="parent_only" <?php checked($variation_strategy, 'parent_only'); ?> />
              <?php esc_html_e('Parent only: ignore variations', 'wc-vector-indexing'); ?></label>
          </td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e('Manual actions include variations', 'wc-vector-indexing'); ?></th>
          <td>
            <label>
              <input type="checkbox" name="manual_include_vars" <?php checked($manual_include_vars, true); ?> />
              <?php esc_html_e('When using Products bulk/row actions, also enqueue child variations.', 'wc-vector-indexing'); ?>
            </label>
          </td>
        </tr>
      </tbody>
    </table>

    <h2><?php esc_html_e('Logs & Diagnostics', 'wc-vector-indexing'); ?></h2>
    <table class="form-table" role="presentation">
      <tbody>
        <tr>
          <th scope="row"><?php esc_html_e('Event log retention (days)', 'wc-vector-indexing'); ?></th>
          <td>
            <input type="number" min="1" max="90" name="retention_days" value="<?php echo esc_attr((string)$retention_days); ?>" />
            <p class="description"><?php esc_html_e('Older JSONL log files will be pruned automatically.', 'wc-vector-indexing'); ?></p>
          </td>
        </tr>
      </tbody>
    </table>

    <h2><?php esc_html_e('Compatibility (advanced)', 'wc-vector-indexing'); ?></h2>
    <table class="form-table" role="presentation">
      <tbody>
        <tr>
          <th scope="row"><?php esc_html_e('Allow manual dimension override', 'wc-vector-indexing'); ?></th>
          <td>
            <label>
              <input type="checkbox" name="allow_dim_override" <?php checked($allow_dim_override, true); ?> />
              <?php esc_html_e('Dangerous. Only enable if you know what you’re doing (dimension mismatches will still block embeds).', 'wc-vector-indexing'); ?>
            </label>
          </td>
        </tr>
      </tbody>
    </table>

    <p><button class="button button-primary"><?php esc_html_e('Save settings', 'wc-vector-indexing'); ?></button></p>
  </form>

  <hr />
  <h2><?php esc_html_e('Danger Zone', 'wc-vector-indexing'); ?></h2>
  <p class="description"><?php esc_html_e('Site-wide purge controls appear here in P8.3.', 'wc-vector-indexing'); ?></p>
</div>

<?php
/** @var string $nonce_purge */
/** @var int    $site_id */
?>
<?php if (!empty($_GET['notice']) && $_GET['notice']==='purge_queued'): ?>
  <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Purge job queued. Vectors will be removed in the background.', 'wc-vector-indexing'); ?></p></div>
<?php elseif (!empty($_GET['notice']) && $_GET['notice']==='purge_invalid'): ?>
  <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Confirmation failed. Type DELETE and tick the checkbox to proceed.', 'wc-vector-indexing'); ?></p></div>
<?php endif; ?>

<div style="border:1px solid #d63638; padding:16px; background:#fff5f5; max-width:740px;">
  <h3 style="margin-top:0; color:#d63638;"><?php esc_html_e('Danger Zone: Delete all vectors for this site', 'wc-vector-indexing'); ?></h3>
  <p><?php printf(
      esc_html__('This will delete all vectors with metadata site_id=%d from Pinecone and OpenAI Vector Store, and clear local records/logs. This cannot be undone.', 'wc-vector-indexing'),
      (int) $site_id
  ); ?></p>

  <form method="post" onsubmit="return confirm('<?php echo esc_js(__('This will permanently remove vectors for this site. Proceed?', 'wc-vector-indexing')); ?>');">
    <input type="hidden" name="action" value="wcvec_advanced_purge" />
    <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce_purge); ?>" />

    <p>
      <label><input type="checkbox" name="purge_confirm" /> <?php esc_html_e('I understand this action is destructive.', 'wc-vector-indexing'); ?></label>
    </p>
    <p>
      <label>
        <?php esc_html_e('Type DELETE to confirm:', 'wc-vector-indexing'); ?>
        <input type="text" name="purge_type" value="" style="width:120px;" />
      </label>
    </p>

    <p>
      <button type="submit" class="button button-secondary" style="border-color:#d63638; color:#d63638;">
        <?php esc_html_e('Delete all vectors for this site', 'wc-vector-indexing'); ?>
      </button>
    </p>
  </form>
</div>
