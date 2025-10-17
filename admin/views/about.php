<?php
/**
 * About View
 *
 * Variables provided by controller:
 * - $company_name
 * - $blurb
 * - $logo_url
 * - $support_url
 * - $contact_email
 * - $products (array of [title, desc, url, icon])
 *
 * @package WCVec
 */

defined('ABSPATH') || exit;

$action_url = add_query_arg(['page' => 'wcvec', 'tab' => 'about'], admin_url('admin.php'));
?>
<div class="wcvec-about">

  <form method="post" action="<?php echo esc_url($action_url); ?>">
    <?php wp_nonce_field('wcvec_about_save', 'wcvec_nonce'); ?>
    <input type="hidden" name="wcvec_action" value="save_about" />

    <div class="card">
      <h2><?php esc_html_e('Company Info', 'wc-vector-indexing'); ?></h2>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="wcvec_about_company_name"><?php esc_html_e('Company Name', 'wc-vector-indexing'); ?></label></th>
          <td><input type="text" class="regular-text" id="wcvec_about_company_name" name="wcvec_about_company_name" value="<?php echo esc_attr($company_name); ?>" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="wcvec_about_company_blurb"><?php esc_html_e('Blurb', 'wc-vector-indexing'); ?></label></th>
          <td>
            <textarea class="large-text" rows="5" id="wcvec_about_company_blurb" name="wcvec_about_company_blurb"><?php echo esc_textarea($blurb); ?></textarea>
            <p class="description"><?php esc_html_e('You can use basic formatting. Links are allowed.', 'wc-vector-indexing'); ?></p>
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="wcvec_about_company_logo_url"><?php esc_html_e('Logo URL', 'wc-vector-indexing'); ?></label></th>
          <td><input type="text" class="regular-text" id="wcvec_about_company_logo_url" name="wcvec_about_company_logo_url" value="<?php echo esc_attr($logo_url); ?>" placeholder="https://example.com/logo.svg" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="wcvec_about_support_url"><?php esc_html_e('Support URL', 'wc-vector-indexing'); ?></label></th>
          <td><input type="text" class="regular-text" id="wcvec_about_support_url" name="wcvec_about_support_url" value="<?php echo esc_attr($support_url); ?>" placeholder="https://example.com/support" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="wcvec_about_contact_email"><?php esc_html_e('Contact Email', 'wc-vector-indexing'); ?></label></th>
          <td><input type="email" class="regular-text" id="wcvec_about_contact_email" name="wcvec_about_contact_email" value="<?php echo esc_attr($contact_email); ?>" placeholder="hello@example.com" /></td>
        </tr>
      </table>
    </div>

    <div class="card">
      <h2><?php esc_html_e('Products / Plugins / Software', 'wc-vector-indexing'); ?></h2>

      <p>
        <button type="button" class="button" id="wcvec-about-add-row"><?php esc_html_e('Add Product', 'wc-vector-indexing'); ?></button>
      </p>

      <table class="widefat fixed striped wcvec-about-table" id="wcvec-about-table">
        <thead>
          <tr>
            <th class="col-drag">⇅</th>
            <th><?php esc_html_e('Title', 'wc-vector-indexing'); ?></th>
            <th><?php esc_html_e('Description', 'wc-vector-indexing'); ?></th>
            <th><?php esc_html_e('URL', 'wc-vector-indexing'); ?></th>
            <th><?php esc_html_e('Icon URL', 'wc-vector-indexing'); ?></th>
            <th class="col-actions"><?php esc_html_e('Actions', 'wc-vector-indexing'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $i = 0;
          foreach ($products as $row) :
              $title = isset($row['title']) ? $row['title'] : '';
              $desc  = isset($row['desc']) ? $row['desc'] : '';
              $url   = isset($row['url']) ? $row['url'] : '';
              $icon  = isset($row['icon']) ? $row['icon'] : '';
          ?>
          <tr class="wcvec-about-row">
            <td class="col-drag">⇅</td>
            <td><input type="text" class="regular-text" name="wcvec_about_products[<?php echo esc_attr((string) $i); ?>][title]" value="<?php echo esc_attr($title); ?>" /></td>
            <td><textarea class="large-text" rows="2" name="wcvec_about_products[<?php echo esc_attr((string) $i); ?>][desc]"><?php echo esc_textarea($desc); ?></textarea></td>
            <td><input type="text" class="regular-text" name="wcvec_about_products[<?php echo esc_attr((string) $i); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com/..." /></td>
            <td><input type="text" class="regular-text" name="wcvec_about_products[<?php echo esc_attr((string) $i); ?>][icon]" value="<?php echo esc_attr($icon); ?>" placeholder="https://example.com/icon.svg" /></td>
            <td class="col-actions">
              <button type="button" class="button-link-delete wcvec-about-remove-row"><?php esc_html_e('Remove', 'wc-vector-indexing'); ?></button>
            </td>
          </tr>
          <?php
              $i++;
          endforeach;
          ?>
        </tbody>
      </table>

      <script type="text/template" id="wcvec-about-row-template">
        <tr class="wcvec-about-row">
          <td class="col-drag">⇅</td>
          <td><input type="text" class="regular-text" name="wcvec_about_products[__INDEX__][title]" value="" /></td>
          <td><textarea class="large-text" rows="2" name="wcvec_about_products[__INDEX__][desc]"></textarea></td>
          <td><input type="text" class="regular-text" name="wcvec_about_products[__INDEX__][url]" value="" placeholder="https://example.com/..." /></td>
          <td><input type="text" class="regular-text" name="wcvec_about_products[__INDEX__][icon]" value="" placeholder="https://example.com/icon.svg" /></td>
          <td class="col-actions">
            <button type="button" class="button-link-delete wcvec-about-remove-row"><?php esc_html_e('Remove', 'wc-vector-indexing'); ?></button>
          </td>
        </tr>
      </script>
    </div>

    <p>
      <button type="submit" class="button button-primary"><?php esc_html_e('Save About', 'wc-vector-indexing'); ?></button>
    </p>
  </form>

  <hr />

  <div class="card">
    <h2><?php esc_html_e('Preview', 'wc-vector-indexing'); ?></h2>
    <div class="wcvec-about-preview">
      <?php if (!empty($logo_url)) : ?>
        <p><img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($company_name ?: __('Company logo', 'wc-vector-indexing')); ?>" style="max-height:60px;" /></p>
      <?php endif; ?>
      <?php if (!empty($company_name)) : ?>
        <h3><?php echo esc_html($company_name); ?></h3>
      <?php endif; ?>
      <?php if (!empty($blurb)) : ?>
        <div class="description"><?php echo wp_kses_post(wpautop($blurb)); ?></div>
      <?php endif; ?>

      <?php if (!empty($products)) : ?>
        <div class="wcvec-about-grid">
          <?php foreach ($products as $row) :
            $title = isset($row['title']) ? $row['title'] : '';
            $desc  = isset($row['desc']) ? $row['desc'] : '';
            $url   = isset($row['url']) ? $row['url'] : '';
            $icon  = isset($row['icon']) ? $row['icon'] : '';
          ?>
            <div class="wcvec-about-card">
              <?php if ($icon) : ?>
                <div class="wcvec-about-card-icon">
                  <img src="<?php echo esc_url($icon); ?>" alt="" />
                </div>
              <?php endif; ?>
              <div class="wcvec-about-card-body">
                <?php if ($title) : ?>
                  <h4>
                    <?php if ($url) : ?>
                      <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($title); ?></a>
                    <?php else : ?>
                      <?php echo esc_html($title); ?>
                    <?php endif; ?>
                  </h4>
                <?php endif; ?>
                <?php if ($desc) : ?>
                  <p><?php echo esc_html($desc); ?></p>
                <?php endif; ?>
                <?php if ($url && !$title) : ?>
                  <p><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Learn more', 'wc-vector-indexing'); ?></a></p>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else : ?>
        <p class="description"><?php esc_html_e('No products added yet.', 'wc-vector-indexing'); ?></p>
      <?php endif; ?>

      <p class="wcvec-about-links">
        <?php if (!empty($support_url)) : ?>
          <a class="button" href="<?php echo esc_url($support_url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Support', 'wc-vector-indexing'); ?></a>
        <?php endif; ?>
        <?php if (!empty($contact_email)) : ?>
          <a class="button" href="mailto:<?php echo antispambot(esc_attr($contact_email)); ?>"><?php esc_html_e('Contact', 'wc-vector-indexing'); ?></a>
        <?php endif; ?>
      </p>
    </div>
  </div>

</div>
