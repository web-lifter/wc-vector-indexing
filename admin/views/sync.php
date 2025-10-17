<?php
/** @var string $nonce_save */
/** @var string $nonce_scan */
/** @var string $nonce_requeue */
/** @var array  $metrics */
/** @var array  $cadences */

$auto   = (bool) ($metrics['auto_sync'] ?? true);
$cad    = (string) ($metrics['cadence'] ?? '15min');
$conc   = (int)    ($metrics['max_concurrent'] ?? 3);
$bsize  = (int)    ($metrics['scan_batch_limit'] ?? 200);
$acf    = (bool)   ($metrics['acf_hook'] ?? true);

$queue  = (array) ($metrics['queue'] ?? []);
$last   = $metrics['last_scan'] ?? '—';
$next   = $metrics['next_scan'] ?? '—';
$backlog= (int)    ($metrics['backlog_estimate'] ?? 0);
?>
<div class="wrap">
  <h1><?php esc_html_e('Vector Indexing – Sync', 'wc-vector-indexing'); ?></h1>

  <?php if (!empty($_GET['notice']) && $_GET['notice']==='saved'): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'wc-vector-indexing'); ?></p></div>
  <?php elseif (!empty($_GET['notice']) && $_GET['notice']==='scan'): ?>
    <div class="notice notice-info is-dismissible"><p><?php esc_html_e('Scan queued to run shortly.', 'wc-vector-indexing'); ?></p></div>
  <?php elseif (!empty($_GET['notice']) && $_GET['notice']==='requeue'): ?>
    <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Reindex jobs enqueued for products with errors.', 'wc-vector-indexing'); ?></p></div>
  <?php endif; ?>

  <form method="post" style="margin-bottom:20px;">
    <input type="hidden" name="action" value="wcvec_sync_save" />
    <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce_save); ?>" />

    <table class="form-table" role="presentation">
      <tbody>
        <tr>
          <th scope="row"><?php esc_html_e('Enable automatic sync', 'wc-vector-indexing'); ?></th>
          <td>
            <label><input type="checkbox" name="auto_sync" <?php checked($auto, true); ?> />
              <?php esc_html_e('Run periodic scans in the background', 'wc-vector-indexing'); ?>
            </label>
          </td>
        </tr>

        <tr>
          <th scope="row"><?php esc_html_e('Scan cadence', 'wc-vector-indexing'); ?></th>
          <td>
            <select name="cadence">
              <?php foreach ($cadences as $key=>$label): ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($cad, $key); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
            </select>
          </td>
        </tr>

        <tr>
          <th scope="row"><?php esc_html_e('Max concurrent index jobs', 'wc-vector-indexing'); ?></th>
          <td>
            <input type="number" min="1" max="10" name="max_concurrent" value="<?php echo esc_attr((string)$conc); ?>" />
            <p class="description"><?php esc_html_e('Upper bound on simultaneous indexing jobs (Action Scheduler).', 'wc-vector-indexing'); ?></p>
          </td>
        </tr>

        <tr>
          <th scope="row"><?php esc_html_e('Scan batch limit', 'wc-vector-indexing'); ?></th>
          <td>
            <input type="number" min="20" max="2000" name="scan_batch_limit" value="<?php echo esc_attr((string)$bsize); ?>" />
            <p class="description"><?php esc_html_e('Maximum products considered per scan cycle.', 'wc-vector-indexing'); ?></p>
          </td>
        </tr>

        <tr>
          <th scope="row"><?php esc_html_e('ACF hook', 'wc-vector-indexing'); ?></th>
          <td>
            <label><input type="checkbox" name="acf_hook" <?php checked($acf, true); ?> />
              <?php esc_html_e('Index products when ACF fields save', 'wc-vector-indexing'); ?>
            </label>
          </td>
        </tr>
      </tbody>
    </table>

    <p>
      <button class="button button-primary"><?php esc_html_e('Save settings', 'wc-vector-indexing'); ?></button>
    </p>
  </form>

  <h2><?php esc_html_e('Queue & Health', 'wc-vector-indexing'); ?></h2>
  <table class="widefat striped" style="max-width:720px">
    <tbody>
      <tr>
        <td><strong><?php esc_html_e('Last scan (UTC)', 'wc-vector-indexing'); ?></strong></td>
        <td><?php echo esc_html($last ?: '—'); ?></td>
      </tr>
      <tr>
        <td><strong><?php esc_html_e('Next scan (UTC)', 'wc-vector-indexing'); ?></strong></td>
        <td><?php echo esc_html($next ?: '—'); ?></td>
      </tr>
      <tr>
        <td><strong><?php esc_html_e('Pending jobs', 'wc-vector-indexing'); ?></strong></td>
        <td><?php echo is_null($queue['pending'] ?? null) ? '—' : (int) $queue['pending']; ?></td>
      </tr>
      <tr>
        <td><strong><?php esc_html_e('In-progress jobs', 'wc-vector-indexing'); ?></strong></td>
        <td><?php echo is_null($queue['in_progress'] ?? null) ? '—' : (int) $queue['in_progress']; ?></td>
      </tr>
      <tr>
        <td><strong><?php esc_html_e('Failed jobs', 'wc-vector-indexing'); ?></strong></td>
        <td><?php echo is_null($queue['failed'] ?? null) ? '—' : (int) $queue['failed']; ?></td>
      </tr>
      <tr>
        <td><strong><?php esc_html_e('Completed (last 24h)', 'wc-vector-indexing'); ?></strong></td>
        <td><?php echo is_null($queue['completed_24h'] ?? null) ? '—' : (int) $queue['completed_24h']; ?></td>
      </tr>
      <tr>
        <td><strong><?php esc_html_e('Failed (last 24h)', 'wc-vector-indexing'); ?></strong></td>
        <td><?php echo is_null($queue['failed_24h'] ?? null) ? '—' : (int) $queue['failed_24h']; ?></td>
      </tr>
      <tr>
        <td><strong><?php esc_html_e('Backlog estimate (next scan cap)', 'wc-vector-indexing'); ?></strong></td>
        <td><?php echo (int) $backlog; ?></td>
      </tr>
    </tbody>
  </table>

  <form method="post" style="margin-top:16px; display:inline-block;">
    <input type="hidden" name="action" value="wcvec_sync_run_scan" />
    <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce_scan); ?>" />
    <button class="button button-primary"><?php esc_html_e('Run scan now', 'wc-vector-indexing'); ?></button>
  </form>

  <form method="post" style="margin-top:16px; display:inline-block; margin-left:8px;">
    <input type="hidden" name="action" value="wcvec_sync_requeue_errors" />
    <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce_requeue); ?>" />
    <button class="button"><?php esc_html_e('Requeue all errors', 'wc-vector-indexing'); ?></button>
  </form>

  <?php if (!function_exists('as_get_scheduled_actions')): ?>
    <div class="notice notice-warning" style="margin-top:16px;">
      <p><?php esc_html_e('Action Scheduler not detected. Using WP-Cron fallback with limited queue metrics. For best reliability, ensure WooCommerce (or Action Scheduler plugin) is active.', 'wc-vector-indexing'); ?></p>
    </div>
  <?php endif; ?>
</div>
