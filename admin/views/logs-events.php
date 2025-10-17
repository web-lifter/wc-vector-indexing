<?php
/** @var array $filters */
/** @var array $rows */
/** @var bool  $has_more */
/** @var string $export_url */

$base_url = add_query_arg(['page'=>'wcvec','tab'=>'logs','view'=>'events'], admin_url('admin.php'));
?>
<div class="wrap">
  <h1><?php esc_html_e('Event Logs', 'wc-vector-indexing'); ?></h1>

  <p>
    <a class="button" href="<?php echo esc_url(add_query_arg(['view'=>'chunks'], $base_url)); ?>">
      <?php esc_html_e('View Chunk State', 'wc-vector-indexing'); ?>
    </a>
  </p>

  <form method="get" style="margin-bottom:10px;">
    <input type="hidden" name="page" value="wcvec" />
    <input type="hidden" name="tab" value="logs" />
    <input type="hidden" name="view" value="events" />
    <fieldset style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <label><?php esc_html_e('Product ID', 'wc-vector-indexing'); ?>
        <input type="number" name="product_id" value="<?php echo esc_attr((string)($filters['product_id'] ?? 0)); ?>" class="small-text" />
      </label>
      <label><?php esc_html_e('Target', 'wc-vector-indexing'); ?>
        <select name="target">
          <option value=""><?php esc_html_e('Any', 'wc-vector-indexing'); ?></option>
          <option value="pinecone" <?php selected($filters['target'] ?? '', 'pinecone'); ?>>Pinecone</option>
          <option value="openai" <?php selected($filters['target'] ?? '', 'openai'); ?>>OpenAI Vector Store</option>
        </select>
      </label>
      <label><?php esc_html_e('Action', 'wc-vector-indexing'); ?>
        <select name="action_f">
          <option value=""><?php esc_html_e('Any', 'wc-vector-indexing'); ?></option>
          <?php foreach (['job','job_enqueue','upsert','adapter_upsert','adapter_delete','delete','scan','validate_openai','validate_pinecone'] as $a): ?>
            <option value="<?php echo esc_attr($a); ?>" <?php selected($filters['action'] ?? '', $a); ?>><?php echo esc_html($a); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?php esc_html_e('Outcome', 'wc-vector-indexing'); ?>
        <select name="outcome">
          <option value=""><?php esc_html_e('Any', 'wc-vector-indexing'); ?></option>
          <?php foreach (['success','error','info'] as $o): ?>
            <option value="<?php echo esc_attr($o); ?>" <?php selected($filters['outcome'] ?? '', $o); ?>><?php echo esc_html(ucfirst($o)); ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><?php esc_html_e('Per page', 'wc-vector-indexing'); ?>
        <input type="number" name="per_page" min="10" max="200" value="<?php echo esc_attr((string)($filters['per_page'] ?? 50)); ?>" class="small-text" />
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
        <th><?php esc_html_e('Time (UTC)', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Product', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Target', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Action', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Outcome', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Message', 'wc-vector-indexing'); ?></th>
        <th><?php esc_html_e('Details', 'wc-vector-indexing'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7"><?php esc_html_e('No events found for the selected filters.', 'wc-vector-indexing'); ?></td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><code><?php echo esc_html($r['ts'] ?? ''); ?></code></td>
          <td>
            <?php if (!empty($r['product_id'])): ?>
              <a href="<?php echo esc_url(get_edit_post_link((int) $r['product_id'])); ?>">#<?php echo (int) $r['product_id']; ?></a>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><?php echo esc_html($r['target'] ?? '—'); ?></td>
          <td><strong><?php echo esc_html($r['action'] ?? ''); ?></strong></td>
          <td>
            <?php
              $out = (string) ($r['outcome'] ?? '');
              $cls = $out === 'success' ? 'tag-green' : ($out === 'error' ? 'tag-red' : 'tag-gray');
            ?>
            <span class="wcvec-tag <?php echo esc_attr($cls); ?>"><?php echo esc_html(ucfirst($out)); ?></span>
          </td>
          <td><?php echo esc_html($r['message'] ?? ''); ?></td>
          <td>
            <?php
            $details = $r['details'] ?? null;
            if (is_array($details) || is_object($details)) {
              echo '<details><summary>' . esc_html__('View', 'wc-vector-indexing') . '</summary><pre style="white-space:pre-wrap;">' .
                   esc_html(wp_json_encode($details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) .
                   '</pre></details>';
            } else {
              echo $details ? esc_html((string)$details) : '—';
            }
            ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <?php if ($has_more): ?>
    <p><em><?php esc_html_e('More events available. Increase “Per page” or narrow filters.', 'wc-vector-indexing'); ?></em></p>
  <?php endif; ?>

  <p style="margin-top:10px;">
    <a class="button" href="<?php echo esc_url(add_query_arg(['view'=>'chunks'], $base_url)); ?>">
      <?php esc_html_e('Back to Chunk State', 'wc-vector-indexing'); ?>
    </a>
  </p>
</div>
