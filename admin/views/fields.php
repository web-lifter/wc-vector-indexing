<?php
/**
 * Fields View
 *
 * Variables:
 * - $selection (current selection map)
 * - $catalog   (discovered catalog)
 *
 * @package WCVec
 */

defined('ABSPATH') || exit;

$action_url = add_query_arg(['page' => 'wcvec', 'tab' => 'fields'], admin_url('admin.php'));

$sel = wp_parse_args($selection, [
  'core'=>[], 'tax'=>[], 'attributes'=>[], 'seo'=>[], 'meta'=>[], 'acf'=>[], 'flags'=>['show_private_meta'=>false]
]);

?>
<div class="wcvec-fields wcvec-two-col">

  <form method="post" action="<?php echo esc_url($action_url); ?>" id="wcvec-fields-form">
    <?php wp_nonce_field('wcvec_fields_save', 'wcvec_nonce'); ?>
    <input type="hidden" name="wcvec_action" value="save_fields" />

    <div class="wcvec-col-left">

      <div class="card">
        <h2 class="wcvec-card-title">
          <?php esc_html_e('Field Selection', 'wc-vector-indexing'); ?>
        </h2>

        <p>
          <input type="search" id="wcvec-fields-search" class="regular-text" placeholder="<?php esc_attr_e('Search fields…', 'wc-vector-indexing'); ?>" />
          <button type="button" class="button" id="wcvec-select-essentials"><?php esc_html_e('Select essentials', 'wc-vector-indexing'); ?></button>
          <label style="margin-left:10px;">
            <input type="checkbox" name="wcvec_show_private_meta" id="wcvec_show_private_meta" value="1" <?php checked(!empty($sel['flags']['show_private_meta'])); ?> />
            <?php esc_html_e('Show private meta (_*)', 'wc-vector-indexing'); ?>
          </label>
        </p>

        <div class="wcvec-field-groups">

          <!-- Core -->
          <div class="wcvec-field-group" data-group="core">
            <h3><?php esc_html_e('Core', 'wc-vector-indexing'); ?></h3>
            <ul class="wcvec-field-list">
              <?php foreach ($catalog['core'] as $row): 
                $key = $row['key']; $label = $row['label'];
                $checked = in_array($key, $sel['core'], true);
              ?>
                <li class="wcvec-field-item" data-key="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($label); ?>" data-essential="<?php echo in_array($key, ['title','short_description','description','sku'], true) ? '1':'0'; ?>">
                  <label>
                    <input type="checkbox" name="wcvec_core[]" value="<?php echo esc_attr($key); ?>" <?php checked($checked); ?> />
                    <span class="wcvec-field-label"><?php echo esc_html($label); ?></span>
                    <code class="wcvec-field-code"><?php echo esc_html($key); ?></code>
                  </label>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <!-- Taxonomies -->
          <div class="wcvec-field-group" data-group="tax">
            <h3><?php esc_html_e('Taxonomies', 'wc-vector-indexing'); ?></h3>
            <ul class="wcvec-field-list">
              <?php foreach ($catalog['tax'] as $row):
                $slug = $row['slug']; $label = $row['label'];
                $checked = in_array($slug, $sel['tax'], true);
              ?>
                <li class="wcvec-field-item" data-key="<?php echo esc_attr($slug); ?>" data-label="<?php echo esc_attr($label); ?>" data-essential="<?php echo $slug==='product_cat' ? '1':'0'; ?>">
                  <label>
                    <input type="checkbox" name="wcvec_tax[]" value="<?php echo esc_attr($slug); ?>" <?php checked($checked); ?> />
                    <span class="wcvec-field-label"><?php echo esc_html($label); ?></span>
                    <code class="wcvec-field-code"><?php echo esc_html($slug); ?></code>
                  </label>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <!-- Attributes -->
          <div class="wcvec-field-group" data-group="attributes">
            <h3><?php esc_html_e('Attributes', 'wc-vector-indexing'); ?></h3>
            <ul class="wcvec-field-list">
              <?php foreach ($catalog['attributes'] as $row):
                $slug = $row['slug']; $label = $row['label'];
                $checked = in_array($slug, $sel['attributes'], true);
              ?>
                <li class="wcvec-field-item" data-key="<?php echo esc_attr($slug); ?>" data-label="<?php echo esc_attr($label); ?>">
                  <label>
                    <input type="checkbox" name="wcvec_attributes[]" value="<?php echo esc_attr($slug); ?>" <?php checked($checked); ?> />
                    <span class="wcvec-field-label"><?php echo esc_html($label); ?></span>
                    <code class="wcvec-field-code"><?php echo esc_html($slug); ?></code>
                  </label>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <!-- SEO -->
          <div class="wcvec-field-group" data-group="seo">
            <h3><?php esc_html_e('SEO', 'wc-vector-indexing'); ?></h3>
            <?php if (empty($catalog['seo'])): ?>
              <p class="description"><?php esc_html_e('Yoast or RankMath not detected. Activate a SEO plugin to use SEO fields.', 'wc-vector-indexing'); ?></p>
            <?php endif; ?>
            <ul class="wcvec-field-list">
              <?php foreach ($catalog['seo'] as $row):
                $key = $row['key']; $label = $row['label']; $checked = in_array($key, $sel['seo'], true);
              ?>
                <li class="wcvec-field-item" data-key="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($label); ?>">
                  <label>
                    <input type="checkbox" name="wcvec_seo[]" value="<?php echo esc_attr($key); ?>" <?php checked($checked); ?> />
                    <span class="wcvec-field-label"><?php echo esc_html($label); ?></span>
                    <code class="wcvec-field-code"><?php echo esc_html($key); ?></code>
                  </label>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>

          <!-- Custom Meta (repeater) -->
          <div class="wcvec-field-group" data-group="meta">
            <h3><?php esc_html_e('Custom Meta', 'wc-vector-indexing'); ?></h3>
            <p class="description"><?php esc_html_e('Add meta keys manually. Choose Text or JSON mode for complex values.', 'wc-vector-indexing'); ?></p>

            <p>
              <button type="button" class="button" id="wcvec-meta-add-row"><?php esc_html_e('Add Meta Key', 'wc-vector-indexing'); ?></button>
            </p>

            <table class="widefat striped wcvec-meta-table" id="wcvec-meta-table">
              <thead>
                <tr>
                  <th><?php esc_html_e('Meta Key', 'wc-vector-indexing'); ?></th>
                  <th><?php esc_html_e('Mode', 'wc-vector-indexing'); ?></th>
                  <th class="col-actions"><?php esc_html_e('Actions', 'wc-vector-indexing'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php
                  $i = 0;
                  foreach ($sel['meta'] as $mkey => $mmode): ?>
                  <tr class="wcvec-meta-row">
                    <td><input type="text" class="regular-text" name="wcvec_meta_key[<?php echo esc_attr((string)$i); ?>]" value="<?php echo esc_attr($mkey); ?>" /></td>
                    <td>
                      <select name="wcvec_meta_mode[<?php echo esc_attr((string)$i); ?>]">
                        <option value="text" <?php selected($mmode,'text'); ?>><?php esc_html_e('Text', 'wc-vector-indexing'); ?></option>
                        <option value="json" <?php selected($mmode,'json'); ?>><?php esc_html_e('JSON', 'wc-vector-indexing'); ?></option>
                      </select>
                    </td>
                    <td class="col-actions"><button type="button" class="button-link-delete wcvec-meta-remove-row"><?php esc_html_e('Remove', 'wc-vector-indexing'); ?></button></td>
                  </tr>
                <?php $i++; endforeach; ?>
              </tbody>
            </table>

            <script type="text/template" id="wcvec-meta-row-template">
              <tr class="wcvec-meta-row">
                <td><input type="text" class="regular-text" name="wcvec_meta_key[__INDEX__]" value="" /></td>
                <td>
                  <select name="wcvec_meta_mode[__INDEX__]">
                    <option value="text"><?php esc_html_e('Text', 'wc-vector-indexing'); ?></option>
                    <option value="json"><?php esc_html_e('JSON', 'wc-vector-indexing'); ?></option>
                  </select>
                </td>
                <td class="col-actions"><button type="button" class="button-link-delete wcvec-meta-remove-row"><?php esc_html_e('Remove', 'wc-vector-indexing'); ?></button></td>
              </tr>
            </script>
          </div>

          <!-- ACF -->
          <div class="wcvec-field-group" data-group="acf">
            <h3><?php esc_html_e('Advanced Custom Fields (ACF)', 'wc-vector-indexing'); ?></h3>
            <?php if (empty($catalog['acf'])): ?>
              <p class="description"><?php esc_html_e('ACF not detected or no groups for products. Install/activate ACF and add fields to list them here.', 'wc-vector-indexing'); ?></p>
            <?php else: ?>
              <?php foreach ($catalog['acf'] as $group): ?>
                <details class="wcvec-acf-group" open>
                  <summary><?php echo esc_html($group['group']['title']); ?></summary>
                  <table class="widefat striped wcvec-acf-table">
                    <thead>
                      <tr>
                        <th><?php esc_html_e('Select', 'wc-vector-indexing'); ?></th>
                        <th><?php esc_html_e('Label', 'wc-vector-indexing'); ?></th>
                        <th><?php esc_html_e('Name', 'wc-vector-indexing'); ?></th>
                        <th><?php esc_html_e('Type', 'wc-vector-indexing'); ?></th>
                        <th><?php esc_html_e('Mode', 'wc-vector-indexing'); ?></th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($group['fields'] as $f):
                      $field_key = $f['key'];
                      $name = $f['name'];
                      $label = $f['label'];
                      $type = $f['type'];

                      // preselect if present in selection
                      $found = null;
                      foreach ($sel['acf'] as $row) {
                        if (!empty($row['field_key']) && $row['field_key'] === $field_key) { $found = $row; break; }
                        if (empty($row['field_key']) && !empty($row['name']) && $row['name'] === $name) { $found = $row; break; }
                      }
                      $selected = (bool) $found;
                      $mode = $found ? ($found['mode'] ?? 'text') : 'text';
                    ?>
                      <tr class="wcvec-acf-row" data-key="<?php echo esc_attr($field_key); ?>" data-label="<?php echo esc_attr($label); ?>">
                        <td>
                          <label>
                            <input type="checkbox" name="wcvec_acf[<?php echo esc_attr($field_key); ?>][selected]" value="1" <?php checked($selected); ?> />
                          </label>
                          <input type="hidden" name="wcvec_acf[<?php echo esc_attr($field_key); ?>][group_key]" value="<?php echo esc_attr($group['group']['key']); ?>" />
                          <input type="hidden" name="wcvec_acf[<?php echo esc_attr($field_key); ?>][name]" value="<?php echo esc_attr($name); ?>" />
                          <input type="hidden" name="wcvec_acf[<?php echo esc_attr($field_key); ?>][label]" value="<?php echo esc_attr($label); ?>" />
                          <input type="hidden" name="wcvec_acf[<?php echo esc_attr($field_key); ?>][type]" value="<?php echo esc_attr($type); ?>" />
                        </td>
                        <td><?php echo esc_html($label); ?></td>
                        <td><code><?php echo esc_html($name); ?></code></td>
                        <td><code><?php echo esc_html($type); ?></code></td>
                        <td>
                          <select name="wcvec_acf[<?php echo esc_attr($field_key); ?>][mode]">
                            <option value="text" <?php selected($mode,'text'); ?>><?php esc_html_e('Text', 'wc-vector-indexing'); ?></option>
                            <option value="json" <?php selected($mode,'json'); ?>><?php esc_html_e('JSON', 'wc-vector-indexing'); ?></option>
                          </select>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </details>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

        </div><!-- /.wcvec-field-groups -->

        <p>
          <button type="submit" class="button button-primary"><?php esc_html_e('Save Selection', 'wc-vector-indexing'); ?></button>
        </p>
      </div>
    </div><!-- /.wcvec-col-left -->

    <div class="wcvec-col-right">
      <div class="card">
        <h2><?php esc_html_e('Live Preview', 'wc-vector-indexing'); ?></h2>

        <p>
          <label for="wcvec-preview-product-search"><strong><?php esc_html_e('Sample product', 'wc-vector-indexing'); ?></strong></label><br/>
          <input type="search" id="wcvec-preview-product-search" class="regular-text" placeholder="<?php esc_attr_e('Search by title or SKU…', 'wc-vector-indexing'); ?>" autocomplete="off" />
          <input type="hidden" id="wcvec-preview-product-id" value="" />
          <button type="button" class="button" id="wcvec-preview-refresh"><?php esc_html_e('Refresh Preview', 'wc-vector-indexing'); ?></button>
        </p>

        <div id="wcvec-preview-status" class="wcvec-preview-status" aria-live="polite"></div>
        <pre id="wcvec-preview-text" class="wcvec-preview-text" style="min-height: 160px;"></pre>
      </div>
    </div><!-- /.wcvec-col-right -->
  </form>
</div>
