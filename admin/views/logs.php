<?php
/** @var array $filters */
/** @var array $rows */
/** @var int   $total */
/** @var int   $per_page */
/** @var int   $page */
/** @var string $export_url */
/** @var string $nonce_reindex */
/** @var string $nonce_purge */

$pages = max(1, (int) ceil($total / $per_page));
$base_url = add_query_arg(['page'=>'wcvec','tab'=>'logs'], admin_url('admin.php'));

function wcvec_logs_paginate($current, $pages, $base, $filters) {
  if ($pages <= 1) return;
  echo '<div class="tablenav"><div class="tablenav-pages">';
  for ($p=1; $p<=$pages; $p++) {
    $url = add_query_arg([
      'paged'=>$p,
      'product_id'=>$filters['product_id'] ?: '',
      'target'=>$filters['target'] ?: '',
      'status'=>$filters['status'] ?: '',
      'per_page'=>$filters['per_page'],
    ], $base);
    $class = $p === $current ? ' class="page-numbers current"' : ' class="page-numbers"';
    echo '<a'.$class.' href="'.esc_url($url).'">'.esc_html((string)$p).'</a> ';
  }
  echo '</div></div>';
}
?>

<div class="wrap">
  <h1><?php esc_html_e('Vector Indexing Logs', 'wc-vector-indexing'); ?></h1>

  <?php if (!empty($_GET['notice']) && $_GET['notice'] === 'reindex'): ?>
    <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Reindex job enqueued.', 'wc-vector-indexing'); ?></p></div>
  <?php elseif (!empty($_GET['notice']) && $_GET['notice'] === 'purge'): ?>
    <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('Purge job enqueued.', 'wc-vector-indexing'); ?></p></div>
  <?php endif; ?>

  <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="wcvec-log-filters">
    <input type="hidden" name="page" value="wcvec" />
    <input type="hidden" name="tab" value="logs" />

    <fieldset style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <label>
        <?php esc_html_e('Product ID', 'wc-vector-indexing'); ?>
        <input type="number" name="product_id" min="0" value="<?php echo esc_attr((string)$filters['product_id']); ?>" class="small-text" />
      </label>

      <label>
        <?php esc_html_e('Target', 'wc-vector-indexing'); ?>
        <select name="target">
          <option value=""><?php esc_html_e('Any', 'wc-vector-indexing'); ?></option>
          <option value="pinecone" <?php selected($filters['target'], 'pinecone'); ?>>Pinecone</option>
          <option value="openai" <?php selected($filters['target'], 'openai'); ?>>OpenAI Vector Store</option>
        </select>
      </label>

      <label>
        <?php esc_html_e('Status', 'wc-vector-indexing'); ?>
        <select name="status">
          <option value=""><?php esc_html_e('Any', 'wc-vector-indexing'); ?></option>
          <?php foreach (['synced','pending','error','deleted'] as $st): ?>
            <option value="<?php echo esc_attr($st); ?>" <?php selected($filters['status'], $st); ?>><?php echo esc_html(ucfirst($st)); ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        <?php esc_html_e('Per page', 'wc-vector-indexing'); ?>
        <input type="number" name="per_page" min="5" max="200" value="<?php echo esc_attr((string)$filters['per_page']); ?>" class="small-text" />
      </label>

      <button class="button"><?php esc_html_e('Filter', 'wc-vector-indexing'); ?></button>

      <a class="button button-secondary" href="<?php echo esc_url($export_url); ?>">
        <?php esc_html_e('Export CSV (current filters)', 'wc-vector-indexing'); ?>
      </a>
    </fieldset>
  </form>

  <table class="widefat fixed striped">
    <thead>
      <tr>
        <th><?php esc_html_e('Product', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Target', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Chunk', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Vector ID', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Model/Dim', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Status', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Last Sync', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Updated', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Actions', 'wc-vector-indexing'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="9"><?php esc_html_e('No rows match your filters.', 'wc-vector-indexing'); ?></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td>
            <a href="<?php echo esc_url(get_edit_post_link((int) $r['product_id'])); ?>">#<?php echo (int) $r['product_id']; ?></a>
          </td>
          <td><?php echo esc_html($r['target']); ?></td>
          <td><?php echo (int) $r['chunk_index']; ?></td>
          <td><code style="font-size:11px;"><?php echo esc_html($r['vector_id']); ?></code></td>
          <td><?php echo esc_html($r['model'] . ' / ' . (int) $r['dimension']); ?></td>
          <td>
            <?php
              $st = $r['status'];
              $cls = $st === 'synced' ? 'tag-green' : ($st === 'error' ? 'tag-red' : 'tag-gray');
            ?>
            <span class="wcvec-tag <?php echo esc_attr($cls); ?>"><?php echo esc_html(ucfirst($st)); ?></span>
            <?php if (!empty($r['error_code'])): ?>
              <div class="description"><strong><?php echo esc_html($r['error_code']); ?>:</strong> <?php echo esc_html($r['error_msg']); ?></div>
            <?php endif; ?>
          </td>
          <td><?php echo esc_html($r['last_synced_at'] ?: '—'); ?></td>
          <td><?php echo esc_html($r['updated_at']); ?></td>
          <td>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="wcvec_logs_reindex" />
              <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce_reindex); ?>" />
              <input type="hidden" name="product_id" value="<?php echo (int) $r['product_id']; ?>" />
              <button class="button button-small"><?php esc_html_e('Reindex', 'wc-vector-indexing'); ?></button>
            </form>
            <form method="post" style="display:inline;margin-left:6px;">
              <input type="hidden" name="action" value="wcvec_logs_purge" />
              <input type="hidden" name="_nonce" value="<?php echo esc_attr($nonce_purge); ?>" />
              <input type="hidden" name="product_id" value="<?php echo (int) $r['product_id']; ?>" />
              <button class="button button-small"><?php esc_html_e('Purge', 'wc-vector-indexing'); ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php wcvec_logs_paginate($page, $pages, $base_url, $filters); ?>

</div>
